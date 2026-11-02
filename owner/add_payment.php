<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

// Security: Regenerate session ID periodically
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "owner") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = isset($_SESSION['fullname']) && !empty(trim($_SESSION['fullname']))
    ? htmlspecialchars(trim($_SESSION['fullname']), ENT_QUOTES, 'UTF-8')
    : 'Owner';

$userInitial = strtoupper(substr(trim($userName), 0, 1));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Helper functions for notifications compatibility & AJAX Search (PDO Safe)
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { 
        try {
            $stmt = $conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $ex) {
            return false; 
        }
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
            $stmt->execute([$columnName]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $ex) {
            return false;
        }
    }
}

function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}

/* =========================================================
   AJAX SEARCH HANDLER FOR THE TOP SEARCH BAR MODAL (PDO)
   ========================================================= */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2) {
        $searchTerm = '%' . $query . '%';
        $sql = "
            SELECT id, tracking_no, business_name, status, rental_name 
            FROM rentals 
            WHERE user_id = :uid 
              AND (is_deleted = '0' OR is_deleted = FALSE) 
              AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3)
            LIMIT 5
        ";
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $sql = "
                SELECT id, tracking_no, business_name, status, rental_name 
                FROM rentals 
                WHERE user_id = :uid 
                  AND is_deleted = '0' 
                  AND (tracking_no LIKE :q1 OR business_name LIKE :q2 OR rental_name LIKE :q3)
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($rows)) {
            echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(2,132,199,0.1)';
                $badgeColor = '#0284c7';
                if (strtolower($status) === 'pending') { $badgeBg = 'rgba(217,119,6,0.15)'; $badgeColor = '#d97706'; }
                elseif (strtolower($status) === 'approved' || strtolower($status) === 'active') { $badgeBg = 'rgba(5,150,105,0.15)'; $badgeColor = '#059669'; }

                echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #bae6fd; border-radius: 12px; color: #0f172a; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#e0f2fe\';" onmouseout="this.style.background=\'#f8fafc\';">';
                echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                echo '<div style="font-weight: 700; font-size: 14px; color: #0284c7;">' . htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']) . ' <span style="color: #94a3b8; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name'] ?: $row['rental_name']) . '</div>';
                echo '<div style="font-size: 12px; color: #64748b;">Property: ' . htmlspecialchars($row['rental_name'] ?? 'N/A') . '</div>';
                echo '</div>';
                echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching rental records found.</div>';
        }
    }
    exit;
}

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// Validate an uploaded proof-of-payment file without moving it yet.
// Returns null if there's no file or it's valid; returns an error message string otherwise.
function validatePaymentProofFile($file) {
    if (!isset($file) || !is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed. Please try again.';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return 'File is too large. Maximum size is 5MB.';
    }
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowedMime, true)) {
            return 'Unsupported file type. Please upload a JPG, PNG, WEBP, or PDF.';
        }
    }
    return null;
}

// Move a validated proof-of-payment file into permanent storage.
// Returns the relative path to store in the DB, or null on failure.
function savePaymentProofFile($file) {
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $ext = 'dat';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (isset($mimeToExt[$mime])) {
            $ext = $mimeToExt[$mime];
        }
    }
    if ($ext === 'dat') {
        $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($origExt, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $ext = $origExt;
        }
    }

    $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return null;
    }

    $filename = 'proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/payment_proofs/' . $filename;
    }
    return null;
}

// Get user's rentals for dropdown - Active/Approved rentals (case-insensitive, PDO Safe)
// NOTE: is_deleted can be a real boolean (PostgreSQL) or 0/1 (MySQL), so we try the
// boolean form first and fall back to the numeric form if the DB rejects it.
$rentals = [];
$hasAnyRental = false;
$myRentalStatuses = [];
try {
    $stmt = $conn->prepare("
        SELECT id, business_name, rental_name, tracking_no, monthly_rate 
        FROM rentals 
        WHERE user_id = :uid 
          AND (is_deleted = FALSE OR is_deleted IS NULL) 
          AND LOWER(TRIM(status::text)) IN ('active', 'approved') 
        ORDER BY business_name ASC
    ");
    $stmt->execute([':uid' => $userId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $stmt = $conn->prepare("
            SELECT id, business_name, rental_name, tracking_no, monthly_rate 
            FROM rentals 
            WHERE user_id = :uid 
              AND (is_deleted = 0 OR is_deleted = '0' OR is_deleted IS NULL) 
              AND LOWER(TRIM(status)) IN ('active', 'approved') 
            ORDER BY business_name ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $rentals = [];
    }
}

// If none matched, check if the user has ANY registered (non-deleted) property at all,
// so we can tell them the real reason instead of always saying "Register Rental".
if (empty($rentals)) {
    try {
        $checkStmt = $conn->prepare("
            SELECT id, business_name, rental_name, status 
            FROM rentals 
            WHERE user_id = :uid AND (is_deleted = FALSE OR is_deleted IS NULL)
        ");
        $checkStmt->execute([':uid' => $userId]);
        $existingRentals = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($existingRentals)) {
            $hasAnyRental = true;
            foreach ($existingRentals as $er) {
                $myRentalStatuses[] = ($er['business_name'] ?: $er['rental_name']) . ' — ' . ($er['status'] ?: 'Unknown');
            }
        }
    } catch (PDOException $e) {
        // ignore
    }
}

// Generate OR Number (PDO Safe)
function generateORNumber() {
    global $conn;
    $year = date('Y');
    $month = date('m');
    $prefix = 'OR-' . $year . '-' . $month . '-';
    $like = $prefix . '%';
    
    try {
        $stmt = $conn->prepare("SELECT or_number FROM payments WHERE or_number LIKE :likeval ORDER BY id DESC LIMIT 1");
        $stmt->execute([':likeval' => $like]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $lastNumber = 0;
        if ($row && !empty($row['or_number'])) {
            $parts = explode('-', $row['or_number']);
            $lastNumber = (int)end($parts);
        }
        $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        return $prefix . $newNumber;
    } catch (PDOException $e) {
        return $prefix . '0001';
    }
}

$orNumber = generateORNumber();

// Handle form submission
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_payment') {
    $rentalId = isset($_POST['rental_id']) ? (int)$_POST['rental_id'] : 0;
    $orNumber = isset($_POST['or_number']) ? trim($_POST['or_number']) : '';
    $paymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
    $amountPaid = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0;
    $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
    $referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : '';
    $collectionLocation = isset($_POST['collection_location']) ? trim($_POST['collection_location']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    // Methods that require proof of transaction (e-wallets, bank, card, check)
    $cashlessMethods = ['GCash', 'Maya', 'Bank Transfer', 'Card', 'Check'];
    $validMethods = array_merge(['Cash'], $cashlessMethods);

    if ($rentalId <= 0) {
        $errorMessage = 'Please select a rental property.';
    } elseif (empty($orNumber)) {
        $errorMessage = 'Please enter an OR Number.';
    } elseif (empty($paymentDate)) {
        $errorMessage = 'Please select a payment date.';
    } elseif ($amountPaid <= 0) {
        $errorMessage = 'Please enter a valid amount.';
    } elseif (empty($paymentMethod) || !in_array($paymentMethod, $validMethods, true)) {
        $errorMessage = 'Please select a valid payment method.';
    } elseif (in_array($paymentMethod, $cashlessMethods, true) && empty($referenceNumber)) {
        $errorMessage = 'Please enter the reference/transaction number for ' . h($paymentMethod) . '.';
    } elseif (($proofFileError = validatePaymentProofFile($_FILES['payment_proof'] ?? null)) !== null) {
        $errorMessage = $proofFileError;
    } else {
        try {
            // Verify rental belongs to user and is Active
            $verifyStmt = null;
            try {
                $verifyStmt = $conn->prepare("
                    SELECT id, business_name, rental_name 
                    FROM rentals 
                    WHERE id = :rid AND user_id = :uid 
                      AND (is_deleted = FALSE OR is_deleted IS NULL) 
                      AND LOWER(TRIM(status::text)) IN ('active', 'approved')
                ");
                $verifyStmt->execute([':rid' => $rentalId, ':uid' => $userId]);
            } catch (PDOException $e) {
                $verifyStmt = $conn->prepare("
                    SELECT id, business_name, rental_name 
                    FROM rentals 
                    WHERE id = :rid AND user_id = :uid 
                      AND (is_deleted = 0 OR is_deleted = '0' OR is_deleted IS NULL) 
                      AND LOWER(TRIM(status)) IN ('active', 'approved')
                ");
                $verifyStmt->execute([':rid' => $rentalId, ':uid' => $userId]);
            }
            $rentalData = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$rentalData) {
                $errorMessage = 'Invalid or inactive rental property selected.';
            } else {
                $businessName = !empty($rentalData['business_name']) ? $rentalData['business_name'] : $rentalData['rental_name'];

                // Store reference number in its own column if the schema supports it;
                // otherwise fold it into remarks so no data is lost.
                $hasRefColumn = columnExists($conn, 'payments', 'reference_number');
                $finalRemarks = $remarks;
                if (!empty($referenceNumber) && !$hasRefColumn) {
                    $refLine = 'Ref/Txn No. (' . $paymentMethod . '): ' . $referenceNumber;
                    $finalRemarks = trim($finalRemarks !== '' ? $finalRemarks . "\n" . $refLine : $refLine);
                }

                // Move the uploaded proof-of-payment file (if any) now that everything else is valid.
                $proofPath = null;
                if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                    $proofPath = savePaymentProofFile($_FILES['payment_proof']);
                    if ($proofPath === null) {
                        $finalRemarks = trim($finalRemarks !== '' ? $finalRemarks . "\n" . 'Note: proof of payment upload failed.' : 'Note: proof of payment upload failed.');
                    }
                }

                // Detect which column (if any) the payments table uses to store the proof file path.
                $proofColumnName = null;
                foreach (['proof_image', 'payment_proof', 'receipt_image', 'proof_path', 'attachment'] as $candidate) {
                    if (columnExists($conn, 'payments', $candidate)) {
                        $proofColumnName = $candidate;
                        break;
                    }
                }
                if ($proofPath !== null && $proofColumnName === null) {
                    $finalRemarks = trim($finalRemarks !== '' ? $finalRemarks . "\n" . 'Proof of payment saved at: ' . $proofPath : 'Proof of payment saved at: ' . $proofPath);
                }

                // Build the INSERT dynamically so it adapts to whichever optional columns exist.
                // NOTE: 'collector_id' has been explicitly REMOVED here.
                $fields = [
                    'rental_id'           => $rentalId,
                    'or_number'           => $orNumber,
                    'payment_date'        => $paymentDate,
                    'amount_paid'         => $amountPaid,
                    'payment_method'      => $paymentMethod,
                    'collection_location' => $collectionLocation,
                    'remarks'             => $finalRemarks,
                ];
                if ($hasRefColumn) {
                    $fields['reference_number'] = $referenceNumber;
                }
                if ($proofColumnName !== null && $proofPath !== null) {
                    $fields[$proofColumnName] = $proofPath;
                }

                $columnNames = array_keys($fields);
                $placeholders = array_map(function ($c) { return ':' . $c; }, $columnNames);

                $insertSql = "INSERT INTO payments (" . implode(', ', $columnNames) . ", status, created_at) 
                              VALUES (" . implode(', ', $placeholders) . ", 'Pending', NOW())";
                $insertStmt = $conn->prepare($insertSql);

                $execParams = [];
                foreach ($fields as $col => $val) {
                    $execParams[':' . $col] = $val;
                }
                $insertStmt->execute($execParams);
                
                $paymentId = $conn->lastInsertId();
                $successMessage = 'Payment submitted successfully! OR Number: ' . h($orNumber) . '. Please wait for LGU Staff verification.';
                
                // Get all LGU Staff users for notification
                try {
                    $lguStaffStmt = $conn->prepare("SELECT id FROM users WHERE role = 'lgu_staff' AND status = 'Active'");
                    $lguStaffStmt->execute();
                    $lguStaffs = $lguStaffStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($lguStaffs)) {
                        $notifTitle = 'New Payment for Verification';
                        $notifMessage = "An owner submitted a payment of ₱" . number_format($amountPaid, 2) . " for '$businessName'. OR#: $orNumber. Please verify.";
                        
                        foreach ($lguStaffs as $staff) {
                            $notifInsert = $conn->prepare("
                                INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                                VALUES (:uid, :title, :msg, 'Payment', :relid, 0, 'Unread', NOW())
                            ");
                            $notifInsert->execute([
                                ':uid' => $staff['id'],
                                ':title' => $notifTitle,
                                ':msg' => $notifMessage,
                                ':relid' => $paymentId
                            ]);
                        }
                    }
                } catch (PDOException $e) {
                    // Ignore notification errors if table schema differs slightly
                }
                
                // Also notify Admin for monitoring purposes
                try {
                    $adminStmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'Active'");
                    $adminStmt->execute();
                    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($admins)) {
                        $notifTitle = 'New Payment Submitted';
                        $notifMessage = "Owner submitted a payment of ₱" . number_format($amountPaid, 2) . " for '$businessName'. OR#: $orNumber. Awaiting LGU Staff verification.";
                        
                        foreach ($admins as $admin) {
                            $notifInsert = $conn->prepare("
                                INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                                VALUES (:uid, :title, :msg, 'Payment', :relid, 0, 'Unread', NOW())
                            ");
                            $notifInsert->execute([
                                ':uid' => $admin['id'],
                                ':title' => $notifTitle,
                                ':msg' => $notifMessage,
                                ':relid' => $paymentId
                            ]);
                        }
                    }
                } catch (PDOException $e) {
                    // Ignore notification errors
                }
                
                // Clear form data after success
                $_POST = array();
                $orNumber = generateORNumber();
            }
       } catch (PDOException $e) {
            $errorMessage = 'Database Error: ' . safe_error($e);
        }
    }
}

// Get payment summary for this user (PDO Safe)
$totalPaid = 0;
$totalPending = 0;
try {
    $sumStmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN p.status IN ('Paid', 'Verified') THEN p.amount_paid ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN p.status = 'Pending' THEN p.amount_paid ELSE 0 END), 0) as total_pending
        FROM payments p 
        INNER JOIN rentals r ON p.rental_id = r.id 
        WHERE r.user_id = :uid
    ");
    $sumStmt->execute([':uid' => $userId]);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
    if ($sumRow) {
        $totalPaid = (float)$sumRow['total_paid'];
        $totalPending = (float)$sumRow['total_pending'];
    }
} catch (PDOException $e) {
    $totalPaid = 0;
    $totalPending = 0;
}

// Notifications (PDO Safe)
$unreadNotifCount = 0;
$notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $cntStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (is_read = 0 OR is_read = FALSE) AND user_id = :uid");
            $cntStmt->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $cntStmt->fetchColumn();
        } catch (PDOException $e) {
            $unreadNotifCount = 0;
        }
    }
    try {
        $notifStmt = $conn->prepare("SELECT id, title, message, created_at FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
        $notifStmt->execute([':uid' => $userId]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $notifications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Payment | RENTRACK LGU</title>
    
    <!-- BOOTSTRAP, FONT AWESOME & FONTS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* ==============================================
           LIGHT BLUE SYSTEM THEME - UNIFIED STYLE
        ============================================== */
        :root {
            --bg-light: #f0f9ff;
            --bg-card: #ffffff;
            --card-border: #bae6fd;
            
            --accent-blue: #0284c7;
            --topbar-bg: #0369a1;
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
            --topbar-height: 42px;
            --navbar-height: 64px;

            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-light);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #0f172a;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #e0f2fe; }
        ::-webkit-scrollbar-thumb { background: #38bdf8; border-radius: 20px; }

        /* ===== TOP OFFICIAL BAR ===== */
        .top-official-bar {
            position: fixed; top: 0; left: 0; width: 100vw;
            background: var(--topbar-bg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0 20px; display: flex; justify-content: space-between; align-items: center;
            font-size: 11px; font-weight: 600; z-index: 1050; height: var(--topbar-height);
            color: #fff; box-sizing: border-box;
        }

        .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; }
        .topbar-left i { font-size: 12px; color: #7dd3fc; }
        .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; }
        .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); }
        .topbar-left .portal-title { color: #e0f2fe; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .topbar-right { display: flex; align-items: center; gap: 12px; }

        .cert-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .topbar-subtext { color: #bae6fd; font-size: 11px; font-weight: 500; }

        /* ===== SIDEBAR (WITH SPINNER RL LOGO & TOOLTIPS) ===== */
        .sidebar {
            position: fixed; top: var(--topbar-height); left: 0;
            width: var(--sidebar-width); height: calc(100vh - var(--topbar-height));
            background: #0c4a6e;
            border-right: 1px solid #0284c7;
            padding: 16px 16px 20px;
            display: flex; flex-direction: column; z-index: 1002;
            transition: width 0.3s ease;
            overflow: hidden;
        }

        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed); }

        .sidebar-menu-wrapper {
            flex: 1;
            overflow-y: auto;
            padding-right: 4px;
            margin-bottom: 10px;
        }
        .sidebar-menu-wrapper::-webkit-scrollbar { width: 4px; }
        .sidebar-menu-wrapper::-webkit-scrollbar-track { background: transparent; }
        .sidebar-menu-wrapper::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 10px; }

        .brand-lockup {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 16px; margin-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-decoration: none; color: #fff;
            white-space: nowrap; overflow: hidden;
        }
        body.sidebar-collapsed .brand-lockup { justify-content: center; padding-top: 4px; }

        .logo-ring {
            position: relative; 
            width: 44px !important; 
            height: 44px !important; 
            min-width: 44px !important;
            min-height: 44px !important;
            max-width: 44px !important;
            max-height: 44px !important;
            border-radius: 50% !important;
            border: 2px dashed #38bdf8 !important;
            display: flex !important; 
            align-items: center !important; 
            justify-content: center !important; 
            flex-shrink: 0 !important;
            background: rgba(255, 255, 255, 0.1);
            animation: spinRing 6s linear infinite;
        }
        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .logo-ring .rl-text { 
            font-weight: 800; 
            font-size: 15px; 
            color: #38bdf8; 
            font-family: 'Space Grotesk', sans-serif; 
            line-height: 1; 
            animation: counterSpin 6s linear infinite;
        }
        @keyframes counterSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(-360deg); } }

        .brand-text-wrapper { transition: opacity 0.2s ease; white-space: nowrap; }
        body.sidebar-collapsed .brand-text-wrapper { opacity: 0; width: 0; overflow: hidden; display: none; }

        .brand-text { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; letter-spacing: -0.3px; color: #fff; }
        .brand-tag { font-size: 11px; color: #38bdf8; font-weight: 700; margin-left: 4px; }

        .nav-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #7dd3fc; margin: 14px 0 6px 8px; letter-spacing: 1px; transition: opacity 0.2s; }
        body.sidebar-collapsed .nav-label { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }

        .nav-item {
            display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px;
            color: #e0f2fe; text-decoration: none; font-size: 13px; font-weight: 600; transition: var(--transition); margin-bottom: 2px;
            white-space: nowrap; overflow: hidden; position: relative;
        }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .nav-item.active { background: #0284c7; color: #fff; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3); }

        body.sidebar-collapsed .nav-item span { opacity: 0; width: 0; overflow: hidden; display: none; }
        body.sidebar-collapsed .nav-item { justify-content: center; padding: 10px; }

        body.sidebar-collapsed .sidebar .nav-item:hover::after {
            content: attr(data-title);
            position: absolute;
            left: calc(100% + 14px);
            top: 50%;
            transform: translateY(-50%);
            background: #0369a1;
            color: #bae6fd;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            border: 1px solid #38bdf8;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            z-index: 1050;
            pointer-events: none;
        }

        .sidebar-footer { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.15); }
        body.sidebar-collapsed .sidebar-footer { text-align: center; }

        /* ===== NAVBAR & MODERN SEARCH POP-UP MODAL ===== */
        .navbar-custom {
            position: fixed; top: var(--topbar-height); left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width)); height: var(--navbar-height);
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            padding: 0 24px; display: flex; align-items: center; justify-content: space-between;
            z-index: 1000; transition: var(--transition);
        }
        body.sidebar-collapsed .navbar-custom { left: var(--sidebar-collapsed); width: calc(100% - var(--sidebar-collapsed)); }

        .navbar-left { display: flex; align-items: center; gap: 12px; }

        .sidebar-toggle {
            background: #f0f9ff; border: 1px solid #bae6fd;
            color: var(--accent-blue); width: 38px; height: 38px; border-radius: 10px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
            transition: var(--transition);
        }
        .sidebar-toggle:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

        .page-title-box { display: flex; align-items: center; gap: 8px; }
        .page-title { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
        .page-sub { font-size: 12.5px; color: #64748b; font-weight: 500; }

        .search-wrapper { flex: 1; max-width: 420px; margin: 0 20px; }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px; }
        .search-container input {
            width: 100%; padding: 7px 40px 7px 38px; border-radius: 999px;
            border: 1px solid #bae6fd; background: #f8fafc;
            color: #0f172a; font-size: 13px; outline: none; cursor: pointer; transition: var(--transition);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-shortcut-badge {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 9.5px; background: #e0f2fe; padding: 2px 6px; border-radius: 6px; color: #0369a1; font-weight: 700; border: 1px solid #bae6fd;
        }

        /* --- MODERN SEARCH POP-UP MODAL --- */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(3, 105, 161, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding-top: 10vh;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }

        .search-modal-content {
            width: 90%; max-width: 650px; background: #ffffff;
            border: 1px solid #bae6fd; border-radius: 20px;
            box-shadow: 0 25px 50px rgba(2, 132, 199, 0.2), 0 0 30px rgba(2, 132, 199, 0.1);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }

        .search-modal-header {
            display: flex; align-items: center; padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0; background: #f8fafc; position: relative;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: #0284c7; margin-right: 14px; }
        .search-modal-input {
            flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600;
        }
        .search-modal-input::placeholder { color: #94a3b8; }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .search-modal-close:hover { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }

        .search-modal-body { padding: 20px; max-height: 380px; overflow-y: auto; }
        .search-modal-body::-webkit-scrollbar { width: 6px; }
        .search-modal-body::-webkit-scrollbar-thumb { background: #38bdf8; border-radius: 999px; }

        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 20px;
            background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;
        }
        .search-shortcut-hint kbd {
            background: #e0f2fe; border: 1px solid #bae6fd; padding: 2px 6px; border-radius: 4px; color: #0369a1; font-family: inherit; font-weight: 700;
        }

        .nav-actions { display: flex; align-items: center; gap: 12px; }

        .clock-pill {
            background: #f0f9ff; border: 1px solid #bae6fd;
            padding: 4px 14px; border-radius: 10px; text-align: center;
        }
        .clock-time { font-family: 'Space Grotesk', sans-serif; font-size: 13px; font-weight: 700; color: #0284c7; line-height: 1.1; }
        .clock-date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .icon-btn {
            background: #f0f9ff; border: 1px solid #bae6fd;
            color: #0369a1; width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition);
        }
        .icon-btn:hover { background: #e0f2fe; border-color: #0284c7; }

        .user-profile {
            display: flex; align-items: center; gap: 10px; padding: 3px 12px 3px 3px;
            background: #f0f9ff; border: 1px solid #bae6fd;
            border-radius: 999px; cursor: pointer; transition: var(--transition);
        }
        .user-profile:hover { border-color: #0284c7; }
        .avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: #0284c7; color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px;
        }
        .user-info { display: flex; flex-direction: column; text-align: left; }
        .user-name { font-size: 12px; font-weight: 700; color: #0f172a; line-height: 1.1; }
        .user-role { font-size: 9px; font-weight: 700; color: #0284c7; text-transform: uppercase; }

        /* Dropdowns & Notification Dropdown */
        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: #ffffff; border: 1px solid #bae6fd;
            border-radius: 14px; box-shadow: 0 16px 36px rgba(2, 132, 199, 0.15);
            opacity: 0; visibility: hidden; transform: translateY(-8px); transition: var(--transition); z-index: 100;
        }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .profile-dropdown { width: 190px; padding: 6px; }
        .notification-dropdown { width: 310px; overflow: hidden; }

        .dropdown-item {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px;
            border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: var(--transition);
        }
        .dropdown-item:hover { background: #f0f9ff; color: #0284c7; }
        .dropdown-item.danger { color: #ef4444; }
        .dropdown-item.danger:hover { background: #fef2f2; }

        /* ===== MAIN CONTENT AREA ===== */
        .wrapper { display: flex; min-height: 100vh; padding-top: calc(var(--topbar-height) + var(--navbar-height)); }
        .main { flex: 1; padding: 22px 24px 40px; margin-left: var(--sidebar-width); transition: var(--transition); }
        body.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

        /* HERO CARD */
        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #0284c7, #2563eb);
            box-shadow: 0 8px 22px rgba(2, 132, 199, 0.2); margin-bottom: 18px;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #e0f2fe; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }

        /* PANEL CARDS */
        .panel {
            background: #ffffff; border: 1px solid #bae6fd; border-radius: 16px;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.04); overflow: hidden; margin-bottom: 20px;
        }
        .panel-header {
            padding: 14px 20px; background: #ffffff; border-bottom: 1px solid #bae6fd;
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-header i { color: #0284c7; }
        .panel-body { padding: 20px; }

        /* FORMS & INPUTS */
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 4px; }
        .form-group label .required { color: #ef4444; }
        .form-control, .form-select {
            width: 100%; border: 1px solid #bae6fd; border-radius: 10px; padding: 8px 12px; font-size: 13px; outline: none; background: #f8fafc;
        }
        .form-control:focus, .form-select:focus { border-color: #0284c7; background: #fff; box-shadow: 0 0 10px rgba(2, 132, 199, 0.1); }
        .helper-text { font-size: 11px; color: #64748b; margin-top: 3px; }

        /* PAYMENT METHOD SELECTOR */
        .pm-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px;
        }
        .pm-option { position: relative; }
        .pm-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
        .pm-option label {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
            padding: 14px 8px; border: 1.5px solid #bae6fd; border-radius: 12px; background: #f8fafc;
            cursor: pointer; text-align: center; transition: var(--transition, all 0.2s ease);
            font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 0;
        }
        .pm-option label i { font-size: 20px; color: #64748b; transition: var(--transition, all 0.2s ease); }
        .pm-option label small { font-weight: 500; color: #94a3b8; font-size: 10px; }
        .pm-option label:hover { border-color: #7dd3fc; background: #f0f9ff; }
        .pm-option input[type="radio"]:checked + label {
            border-color: #0284c7; background: #e0f2fe; color: #0284c7; box-shadow: 0 0 0 3px rgba(2,132,199,0.12);
        }
        .pm-option input[type="radio"]:checked + label i,
        .pm-option input[type="radio"]:checked + label small { color: #0284c7; }
        .pm-option.gcash input[type="radio"]:checked + label { border-color: #007dfe; background: #e8f2ff; color: #007dfe; }
        .pm-option.gcash input[type="radio"]:checked + label i,
        .pm-option.gcash input[type="radio"]:checked + label small { color: #007dfe; }
        .pm-option.maya input[type="radio"]:checked + label { border-color: #00b14f; background: #e6f9ee; color: #00913f; }
        .pm-option.maya input[type="radio"]:checked + label i,
        .pm-option.maya input[type="radio"]:checked + label small { color: #00913f; }
        #referenceNumberGroup { display: none; }
        #referenceNumberGroup.show { display: block; }
        .pm-hint { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #0284c7; background: #f0f9ff; border: 1px dashed #bae6fd; border-radius: 8px; padding: 8px 10px; margin-top: 8px; }

        /* PROOF OF PAYMENT UPLOAD */
        .upload-dropzone {
            border: 2px dashed #bae6fd; border-radius: 12px; background: #f8fafc; padding: 24px 16px;
            text-align: center; cursor: pointer; transition: var(--transition, all 0.2s ease); position: relative;
        }
        .upload-dropzone:hover, .upload-dropzone.dragover { border-color: #0284c7; background: #f0f9ff; }
        .upload-dropzone i.upload-icon { font-size: 28px; color: #0284c7; display: block; margin-bottom: 8px; }
        .upload-dropzone .upload-title { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
        .upload-dropzone .upload-sub { font-size: 11px; color: #64748b; }
        .upload-dropzone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-preview {
            display: none; align-items: center; gap: 12px; border: 1px solid #bae6fd; border-radius: 12px;
            background: #f8fafc; padding: 10px 12px; margin-top: 10px;
        }
        .upload-preview.show { display: flex; }
        .upload-preview img { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; border: 1px solid #bae6fd; }
        .upload-preview .file-icon { width: 52px; height: 52px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .upload-preview .file-info { flex: 1; min-width: 0; }
        .upload-preview .file-name { font-size: 12px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .upload-preview .file-size { font-size: 11px; color: #64748b; }
        .upload-preview .remove-file-btn { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; padding: 4px; flex-shrink: 0; }
        .upload-error { display: none; font-size: 11px; color: #ef4444; margin-top: 6px; }
        .upload-error.show { display: block; }

        .btn-primary-custom {
            background: #0284c7; color: #fff; border: none; padding: 10px 18px; border-radius: 10px;
            font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 6px; justify-content: center;
        }
        .btn-primary-custom:hover { background: #0369a1; }

        .btn-secondary-custom {
            background: #f0f9ff; border: 1px solid #bae6fd; color: #0284c7; padding: 8px 16px;
            border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; justify-content: center;
        }
        .btn-secondary-custom:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

        .summary-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px; }
        .summary-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 12px; text-align: center; }
        .summary-card .number { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; color: #0284c7; }
        .summary-card .label { font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; }

        .footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid #bae6fd; display: flex; justify-content: space-between; font-size: 11.5px; color: #64748b; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; top: var(--topbar-height) !important; height: calc(100vh - var(--topbar-height)) !important; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            .navbar-custom { left: 0 !important; width: 100% !important; }
            .main { margin-left: 0 !important; padding: 16px; }
            .clock-pill { display: none; }
        }
    </style>
</head>
<body>

    <!-- 1. TOP OFFICIAL BAR -->
    <div class="top-official-bar">
        <div class="topbar-left">
            <i class="fa-solid fa-landmark"></i>
            <strong>REPUBLIC OF THE PHILIPPINES</strong>
            <span class="divider"></span>
            <span class="portal-title">Local Government Unit Property &amp; Rental Compliance Portal</span>
        </div>
        <div class="topbar-right">
            <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
            <span class="topbar-subtext d-none d-md-inline">City Treasurer &amp; Building Official Online System</span>
        </div>
    </div>

    <!-- 2. SIDEBAR WITH SPINNER RL LOGO & TOOLTIPS -->
    <aside class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand-lockup">
            <div class="logo-ring">
                <span class="rl-text">RL</span>
            </div>
            <div class="brand-text-wrapper">
                <span class="brand-text">RENTRACK <span class="brand-tag">LGU</span></span>
            </div>
        </a>

        <div class="sidebar-menu-wrapper">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item" data-title="Dashboard"><i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span></a>

            <div class="nav-label">Rentals</div>
            <a href="rentals.php" class="nav-item" data-title="My Rentals"><i class="fa-solid fa-building"></i> <span>My Rentals</span></a>
            <a href="rental_requests.php" class="nav-item" data-title="Rental Requests"><i class="fa-solid fa-file-circle-check"></i> <span>Rental Requests</span></a>

            <div class="nav-label">Operations</div>
            <a href="schedule.php" class="nav-item" data-title="Rental Schedule"><i class="fa-solid fa-calendar-days"></i> <span>Rental Schedule</span></a>
            <a href="inspections.php" class="nav-item" data-title="Inspections"><i class="fa-solid fa-clipboard-check"></i> <span>Inspections</span></a>
            <a href="inspection_schedule.php" class="nav-item" data-title="Inspection Schedule"><i class="fa-solid fa-calendar-check"></i> <span>Inspection Schedule</span></a>

            <div class="nav-label">Documents &amp; Compliance</div>
            <a href="documents.php" class="nav-item" data-title="Documents"><i class="fa-solid fa-file-lines"></i> <span>Documents</span></a>
            <a href="renew_documents.php" class="nav-item" data-title="Online Renewal"><i class="fa-solid fa-file-arrow-up"></i> <span>Online Renewal</span></a>
            <a href="compliance.php" class="nav-item" data-title="Compliance"><i class="fa-solid fa-file-shield"></i> <span>Compliance</span></a>

            <div class="nav-label">Financial</div>
            <a href="payments.php" class="nav-item active" data-title="Payments"><i class="fa-solid fa-cash-register"></i> <span>Payments</span></a>

            <div class="nav-label">Reports &amp; Settings</div>
            <a href="reports.php" class="nav-item" data-title="Reports"><i class="fa-solid fa-chart-simple"></i> <span>Reports</span></a>
            <a href="settings.php" class="nav-item" data-title="Settings"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
        </div>

        <div class="sidebar-footer">
            <a href="javascript:void(0)" class="nav-item text-danger" id="sidebarLogoutBtn" data-title="Logout"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></a>
        </div>
    </aside>

    <!-- 3. NAVBAR WITH HAMBURGER TOGGLE, SEARCH BAR & NOTIFICATIONS -->
    <header class="navbar-custom">
        <div class="navbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Navigation"><i class="fa-solid fa-bars"></i></button>
            <div class="page-title-box">
                <h1 class="page-title">Submit Payment</h1>
                <span class="page-sub d-none d-sm-inline">&middot; Register Fee Payment</span>
            </div>
        </div>

        <div class="search-wrapper d-none d-md-block">
            <div class="search-container" onclick="openSearchModal()">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search tracking no, business name, barangay..." readonly>
                <span class="search-shortcut-badge">Ctrl K</span>
            </div>
        </div>

        <div class="nav-actions">
            <div class="clock-pill">
                <div class="clock-time" id="liveTime"><?php echo date('h:i:s A'); ?></div>
                <div class="clock-date"><?php echo date('M d, Y'); ?></div>
            </div>

            <div style="position:relative;">
                <button class="icon-btn" id="notifBtn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span style="position:absolute; top:2px; right:2px; background:#ef4444; color:#fff; font-size:9px; font-weight:700; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center;"><?php echo $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </button>
                <div id="notifDropdown" class="notification-dropdown">
                    <div style="padding:12px 14px; font-weight:700; border-bottom:1px solid #bae6fd; font-size:13px; color:#0f172a; display:flex; justify-content:space-between; align-items:center;">
                        <span>Notifications</span>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span style="font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px;"><?php echo $unreadNotifCount; ?> New</span>
                        <?php endif; ?>
                    </div>
                    <div style="max-height:280px; overflow-y:auto;">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notification): ?>
                                <?php 
                                    $notifLink = 'rentals.php';
                                    if (!empty($notification['link'])) {
                                        $notifLink = $notification['link'];
                                    } else {
                                        $titleLower = strtolower($notification['title'] ?? '');
                                        $msgLower = strtolower($notification['message'] ?? '');
                                        
                                        if (strpos($titleLower, 'rental') !== false || strpos($msgLower, 'rental') !== false) {
                                            $notifLink = 'rentals.php';
                                        } elseif (strpos($titleLower, 'inspection') !== false || strpos($msgLower, 'inspection') !== false) {
                                            $notifLink = 'inspections.php';
                                        } elseif (strpos($titleLower, 'payment') !== false || strpos($msgLower, 'payment') !== false) {
                                            $notifLink = 'payments.php';
                                        } elseif (strpos($titleLower, 'compliance') !== false || strpos($msgLower, 'permit') !== false) {
                                            $notifLink = 'compliance.php';
                                        }
                                    }
                                ?>
                                <a href="<?php echo htmlspecialchars($notifLink); ?>" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:#334155; transition:background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                                    <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                    <span style="font-size:11px; color:#64748b; display:block; margin-top:2px;"><?php echo displayField($notification, ['message','description']); ?></span>
                                    <span style="font-size:9.5px; color:#94a3b8; display:block; margin-top:4px;"><?php echo !empty($notification['created_at']) ? date('M d, Y h:i A', strtotime($notification['created_at'])) : ''; ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">No notifications found</div>
                        <?php endif; ?>
                    </div>
                    <div style="padding:8px; text-align:center; border-top:1px solid #bae6fd; background:#f8fafc;">
                        <a href="notifications.php" style="font-size:11px; font-weight:700; color:#0284c7; text-decoration:none;">View All Notifications</a>
                    </div>
                </div>
            </div>

            <div style="position:relative;">
                <div class="user-profile" id="profileToggle">
                    <div class="avatar"><?php echo h($userInitial); ?></div>
                    <div class="user-info d-none d-sm-flex">
                        <span class="user-name"><?php echo h($userName); ?></span>
                        <span class="user-role">OWNER</span>
                    </div>
                    <i class="fa-solid fa-chevron-down" style="font-size:10px; color:#64748b; margin-left:2px;"></i>
                </div>

                <div class="profile-dropdown" id="profileDropdown">
                    <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> Profile</a>
                    <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                    <div style="height:1px; background:#bae6fd; margin:4px 0;"></div>
                    <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- MODERN SEARCH POP-UP MODAL -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="closeSearchModalOnOutside(event)">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <i class="fa-solid fa-magnifying-glass search-icon-main"></i>
                <input type="text" class="search-modal-input" id="modalSearchInput" placeholder="Search by tracking number, business name, or property..." autocomplete="off">
                <div class="search-modal-close" onclick="closeSearchModal()">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </div>
            <div class="search-modal-body" id="modalSearchResults">
                <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                    Type at least 2 characters to search records...
                </div>
            </div>
            <div class="search-shortcut-hint">
                <span>Quick search across all your property records</span>
                <span>Press <kbd>ESC</kbd> to close</span>
            </div>
        </div>
    </div>

    <!-- 4. MAIN CONTENT AREA -->
    <div class="wrapper">
        <div class="main">

            <!-- Hero Card -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-plus-circle me-1"></i> Submit Payment</h2>
                    <p>Submit your rental registration payment details for LGU Staff verification.</p>
                </div>
                <div>
                    <a href="payments.php" class="btn-secondary-custom" style="background:#ffffff; color:#0284c7; border:none;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Payments
                    </a>
                </div>
            </div>

            <!-- Panel -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-pen-to-square"></i> Payment Details</span>
                    <span class="badge" style="background:#e0f2fe; color:#0284c7; font-size:11px; padding:4px 10px; border-radius:999px;">
                        <i class="fa-solid fa-info-circle"></i> Awaiting Verification
                    </span>
                </div>
                <div class="panel-body">

                    <?php if (!empty($successMessage)): ?>
                        <div class="alert alert-success mb-3" style="border-radius:10px; font-size:13px;"><?php echo h($successMessage); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger mb-3" style="border-radius:10px; font-size:13px;"><?php echo h($errorMessage); ?></div>
                    <?php endif; ?>

                    <!-- Summary Cards -->
                    <?php if ($totalPaid > 0 || $totalPending > 0): ?>
                    <div class="summary-cards">
                        <div class="summary-card">
                            <div class="number" style="color:#059669;"><?php echo formatCurrency($totalPaid); ?></div>
                            <div class="label">Total Verified Payments</div>
                        </div>
                        <div class="summary-card">
                            <div class="number" style="color:#d97706;"><?php echo formatCurrency($totalPending); ?></div>
                            <div class="label">Pending Verification</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($rentals)): ?>
                        <div style="text-align:center; padding:30px; color:#64748b;">
                            <i class="fa-solid fa-building" style="font-size:32px; opacity:0.5; display:block; margin-bottom:8px;"></i>
                            <?php if ($hasAnyRental): ?>
                                <h3 style="font-size:16px; color:#0f172a; font-weight:700;">Property Not Yet Active</h3>
                                <p style="font-size:13px;">You already have a registered property, but it isn't marked "Active" yet, so payment can't be submitted for it.</p>
                                <div style="text-align:left; display:inline-block; margin:10px auto; font-size:12px; background:#f8fafc; border:1px solid #bae6fd; border-radius:10px; padding:10px 14px;">
                                    <?php foreach ($myRentalStatuses as $line): ?>
                                        <div style="padding:3px 0;"><i class="fa-solid fa-circle-info" style="color:#0284c7;"></i> <?php echo h($line); ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <p style="font-size:12px; color:#94a3b8;">Please wait for LGU Staff to approve/activate it, or contact your City Treasurer's Office.</p>
                            <?php else: ?>
                                <h3 style="font-size:16px; color:#0f172a; font-weight:700;">No Active Rental Properties</h3>
                                <p style="font-size:13px;">You need to have an active rental property before submitting a payment.</p>
                                <a href="register_rental.php" class="btn-primary-custom" style="font-size:12px;">
                                    <i class="fa-solid fa-plus"></i> Register Rental
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="paymentForm" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_payment">

                            <div class="row g-3">
                                <div class="col-md-6 form-group">
                                    <label for="rental_id">Rental Property <span class="required">*</span></label>
                                    <select id="rental_id" name="rental_id" class="form-select" required>
                                        <option value="">-- Select Rental --</option>
                                        <?php foreach ($rentals as $rental): ?>
                                            <option value="<?php echo (int)$rental['id']; ?>" <?php echo (isset($_POST['rental_id']) && $_POST['rental_id'] == $rental['id']) ? 'selected' : ''; ?>>
                                                <?php echo h(!empty($rental['business_name']) ? $rental['business_name'] : $rental['rental_name']); ?> (<?php echo h($rental['tracking_no']); ?>)
                                                <?php if (isset($rental['monthly_rate']) && $rental['monthly_rate'] > 0): ?>
                                                    - <?php echo formatCurrency($rental['monthly_rate']); ?>/mo
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="helper-text">Select the rental property you're paying for.</div>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="or_number">OR Number <span class="required">*</span></label>
                                    <input type="text" id="or_number" name="or_number" class="form-control" value="<?php echo isset($_POST['or_number']) ? h($_POST['or_number']) : h($orNumber); ?>" required>
                                    <div class="helper-text">Official Receipt number issued by City Treasurer's Office.</div>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="payment_date">Payment Date <span class="required">*</span></label>
                                    <input type="date" id="payment_date" name="payment_date" class="form-control" value="<?php echo isset($_POST['payment_date']) ? h($_POST['payment_date']) : date('Y-m-d'); ?>" required>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="amount_paid">Amount Paid <span class="required">*</span></label>
                                    <input type="number" id="amount_paid" name="amount_paid" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?php echo isset($_POST['amount_paid']) ? h($_POST['amount_paid']) : ''; ?>" required>
                                    <div class="helper-text">Enter the amount paid in Philippine Pesos (₱).</div>
                                </div>

                                <div class="col-12 form-group">
                                    <label>Payment Method <span class="required">*</span></label>
                                    <?php
                                        $selectedMethod = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
                                        $pmOptions = [
                                            ['value' => 'Cash',         'label' => 'Cash',           'sub' => 'In person',      'icon' => 'fa-solid fa-money-bill-wave', 'class' => ''],
                                            ['value' => 'GCash',        'label' => 'GCash',          'sub' => 'E-Wallet',       'icon' => 'fa-solid fa-mobile-screen-button', 'class' => 'gcash'],
                                            ['value' => 'Maya',         'label' => 'Maya',           'sub' => 'E-Wallet',       'icon' => 'fa-solid fa-wallet', 'class' => 'maya'],
                                            ['value' => 'Bank Transfer','label' => 'Bank Transfer',  'sub' => 'Online/Deposit', 'icon' => 'fa-solid fa-building-columns', 'class' => ''],
                                            ['value' => 'Card',         'label' => 'Debit/Credit',   'sub' => 'Card',           'icon' => 'fa-solid fa-credit-card', 'class' => ''],
                                            ['value' => 'Check',        'label' => 'Check',          'sub' => 'Cheque',         'icon' => 'fa-solid fa-money-check-dollar', 'class' => ''],
                                        ];
                                    ?>
                                    <div class="pm-grid" id="paymentMethodGrid">
                                        <?php foreach ($pmOptions as $i => $opt): ?>
                                            <div class="pm-option <?php echo h($opt['class']); ?>">
                                                <input type="radio" name="payment_method" id="pm_<?php echo $i; ?>" value="<?php echo h($opt['value']); ?>" <?php echo ($selectedMethod === $opt['value']) ? 'checked' : ''; ?> required>
                                                <label for="pm_<?php echo $i; ?>">
                                                    <i class="<?php echo h($opt['icon']); ?>"></i>
                                                    <span><?php echo h($opt['label']); ?></span>
                                                    <small><?php echo h($opt['sub']); ?></small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="helper-text">Choose how this payment was made.</div>
                                </div>

                                <div class="col-md-6 form-group" id="referenceNumberGroup">
                                    <label for="reference_number">Reference / Transaction No. <span class="required" id="refRequiredMark" style="display:none;">*</span></label>
                                    <input type="text" id="reference_number" name="reference_number" class="form-control" placeholder="e.g., GCash Ref. No. or Bank Txn ID" value="<?php echo isset($_POST['reference_number']) ? h($_POST['reference_number']) : ''; ?>">
                                    <div class="helper-text">Enter the confirmation/reference number from your GCash, Maya, bank, or card receipt.</div>
                                </div>

                                <div class="col-12 form-group">
                                    <label for="payment_proof">Proof of Payment <span class="helper-text" style="display:inline; font-weight:400;">(screenshot or photo of receipt, optional but recommended)</span></label>
                                    <div class="upload-dropzone" id="uploadDropzone">
                                        <i class="fa-solid fa-cloud-arrow-up upload-icon"></i>
                                        <div class="upload-title">Drag & drop screenshot here, or click to browse</div>
                                        <div class="upload-sub">JPG, PNG, WEBP, or PDF · Max 5MB</div>
                                        <input type="file" id="payment_proof" name="payment_proof" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf">
                                    </div>
                                    <div class="upload-preview" id="uploadPreview">
                                        <img id="uploadPreviewImg" src="" alt="Preview" style="display:none;">
                                        <div class="file-icon" id="uploadPreviewIcon" style="display:none;"><i class="fa-solid fa-file-pdf"></i></div>
                                        <div class="file-info">
                                            <div class="file-name" id="uploadFileName"></div>
                                            <div class="file-size" id="uploadFileSize"></div>
                                        </div>
                                        <button type="button" class="remove-file-btn" id="removeFileBtn" title="Remove file"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                    <div class="upload-error" id="uploadError"></div>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="collection_location">Collection Location / Bank Branch</label>
                                    <input type="text" id="collection_location" name="collection_location" class="form-control" placeholder="e.g., City Hall Treasurer's Office or BDO - Marilao Branch" value="<?php echo isset($_POST['collection_location']) ? h($_POST['collection_location']) : ''; ?>">
                                    <div class="helper-text">Where the payment was made or received.</div>
                                </div>

                                <div class="col-12 form-group">
                                    <label for="remarks">Remarks</label>
                                    <textarea id="remarks" name="remarks" class="form-control" rows="3" placeholder="Additional notes about this payment..."><?php echo isset($_POST['remarks']) ? h($_POST['remarks']) : ''; ?></textarea>
                                </div>

                                <div class="col-12 d-flex gap-2 pt-2">
                                    <button type="reset" class="btn-secondary-custom flex-grow-1"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="fa-solid fa-paper-plane"></i> Submit for Verification</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Footer -->
            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:#0284c7; font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>

        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        // Realtime Clock
        function updateClock() {
            const timeBox = document.getElementById('liveTime');
            if (!timeBox) return;
            const now = new Date();
            timeBox.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Fullscreen Hamburger Menu Toggle & Sidebar Collapse
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                if (window.innerWidth <= 992) {
                    document.body.classList.toggle('sidebar-open');
                } else {
                    document.body.classList.toggle('sidebar-collapsed');
                }
            });
        }

        // MODERN SEARCH MODAL LOGIC
        const searchModalOverlay = document.getElementById('searchModalOverlay');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        function openSearchModal() {
            searchModalOverlay.classList.add('active');
            setTimeout(() => modalSearchInput.focus(), 100);
        }

        function closeSearchModal() {
            searchModalOverlay.classList.remove('active');
            modalSearchInput.value = '';
            modalSearchResults.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                    Type at least 2 characters to search records...
                </div>
            `;
        }

        function closeSearchModalOnOutside(event) {
            if (event.target === searchModalOverlay) {
                closeSearchModal();
            }
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                openSearchModal();
            }
            if (e.key === 'Escape') {
                closeSearchModal();
            }
        });

        let searchTimeout;
        modalSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            let query = this.value.trim();
            if (query.length < 2) {
                modalSearchResults.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                        <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                        Type at least 2 characters to search records...
                    </div>
                `;
                return;
            }

            modalSearchResults.innerHTML = `
                <div style="text-align: center; padding: 30px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; color: #0284c7;"></i>
                    <div>Searching records...</div>
                </div>
            `;

            searchTimeout = setTimeout(() => {
                fetch('?ajax_action=search&q=' + encodeURIComponent(query))
                    .then(res => res.text())
                    .then(html => {
                        modalSearchResults.innerHTML = html;
                    });
            }, 300);
        });

        // Dropdowns Toggle
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileToggle) {
            profileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
                if (notifDropdown) notifDropdown.classList.remove('show');
            });
        }

        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        if (notifBtn) {
            notifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle('show');
                if (profileDropdown) profileDropdown.classList.remove('show');
            });
        }

        if (notifDropdown) {
            notifDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }

        document.addEventListener('click', () => {
            if (profileDropdown) profileDropdown.classList.remove('show');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });

        // Payment Method: show/require Reference Number for non-cash methods
        const refGroup = document.getElementById('referenceNumberGroup');
        const refInput = document.getElementById('reference_number');
        const refRequiredMark = document.getElementById('refRequiredMark');
        const cashlessMethods = ['GCash', 'Maya', 'Bank Transfer', 'Card', 'Check'];

        function toggleReferenceField() {
            const checked = document.querySelector('input[name="payment_method"]:checked');
            const method = checked ? checked.value : '';
            if (cashlessMethods.includes(method)) {
                refGroup.classList.add('show');
                refInput.required = true;
                refRequiredMark.style.display = 'inline';
            } else {
                refGroup.classList.remove('show');
                refInput.required = false;
                refRequiredMark.style.display = 'none';
            }
        }
        document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.addEventListener('change', toggleReferenceField);
        });
        toggleReferenceField();

        // PROOF OF PAYMENT: drag & drop / click-to-browse upload with preview
        const dropzone = document.getElementById('uploadDropzone');
        const fileInput = document.getElementById('payment_proof');
        const uploadPreview = document.getElementById('uploadPreview');
        const uploadPreviewImg = document.getElementById('uploadPreviewImg');
        const uploadPreviewIcon = document.getElementById('uploadPreviewIcon');
        const uploadFileName = document.getElementById('uploadFileName');
        const uploadFileSize = document.getElementById('uploadFileSize');
        const uploadError = document.getElementById('uploadError');
        const removeFileBtn = document.getElementById('removeFileBtn');

        const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        function showUploadError(msg) {
            uploadError.textContent = msg;
            uploadError.classList.add('show');
        }

        function clearUploadError() {
            uploadError.textContent = '';
            uploadError.classList.remove('show');
        }

        function resetFileInput() {
            fileInput.value = '';
            uploadPreview.classList.remove('show');
            uploadPreviewImg.style.display = 'none';
            uploadPreviewIcon.style.display = 'none';
            uploadPreviewImg.src = '';
        }

        function handleSelectedFile(file) {
            clearUploadError();
            if (!file) return;

            if (!ALLOWED_TYPES.includes(file.type)) {
                showUploadError('Unsupported file type. Please upload a JPG, PNG, WEBP, or PDF.');
                resetFileInput();
                return;
            }
            if (file.size > MAX_FILE_SIZE) {
                showUploadError('File is too large. Maximum size is 5MB.');
                resetFileInput();
                return;
            }

            uploadFileName.textContent = file.name;
            uploadFileSize.textContent = formatFileSize(file.size);
            uploadPreview.classList.add('show');

            if (file.type === 'application/pdf') {
                uploadPreviewImg.style.display = 'none';
                uploadPreviewIcon.style.display = 'flex';
            } else {
                uploadPreviewIcon.style.display = 'none';
                const reader = new FileReader();
                reader.onload = (e) => {
                    uploadPreviewImg.src = e.target.result;
                    uploadPreviewImg.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        }

        fileInput?.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                handleSelectedFile(e.target.files[0]);
            }
        });

        ['dragenter', 'dragover'].forEach(evt => {
            dropzone?.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropzone?.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });
        dropzone?.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files && files[0]) {
                fileInput.files = files;
                handleSelectedFile(files[0]);
            }
        });

        removeFileBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            resetFileInput();
            clearUploadError();
        });

        // Form Validation
        document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
            const amount = document.getElementById('amount_paid');
            if (amount && parseFloat(amount.value) <= 0) {
                e.preventDefault();
                Swal.fire({
                    title: 'Invalid Amount',
                    text: 'Please enter a valid amount greater than zero.',
                    icon: 'error',
                    confirmButtonColor: '#0284c7'
                });
                amount.focus();
                return;
            }

            const checkedMethod = document.querySelector('input[name="payment_method"]:checked');
            if (!checkedMethod) {
                e.preventDefault();
                Swal.fire({
                    title: 'Payment Method Required',
                    text: 'Please select how this payment was made.',
                    icon: 'error',
                    confirmButtonColor: '#0284c7'
                });
                return;
            }

            if (cashlessMethods.includes(checkedMethod.value) && refInput && refInput.value.trim() === '') {
                e.preventDefault();
                Swal.fire({
                    title: 'Reference Number Required',
                    text: 'Please enter the reference/transaction number for this payment method.',
                    icon: 'error',
                    confirmButtonColor: '#0284c7'
                });
                refInput.focus();
                return;
            }

            if (uploadError && uploadError.classList.contains('show')) {
                e.preventDefault();
                Swal.fire({
                    title: 'Invalid Attachment',
                    text: 'Please fix or remove the proof of payment file before submitting.',
                    icon: 'error',
                    confirmButtonColor: '#0284c7'
                });
            }
        });

        // Logout Prompt
        function confirmLogout() {
            Swal.fire({
                title: 'Logout from RENTRACK?',
                text: 'Are you sure you want to log out?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0284c7',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Logout',
                background: '#ffffff',
                color: '#0f172a'
            }).then((res) => {
                if (res.isConfirmed) window.location.href = '../logout.php';
            });
        }

        document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
        document.getElementById('sidebarLogoutBtn')?.addEventListener('click', confirmLogout);
    </script>

</body>
</html>