<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== "owner") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = isset($_SESSION['fullname']) && !empty(trim($_SESSION['fullname']))
    ? trim($_SESSION['fullname'])
    : 'Owner';  // raw value; i-escape lang sa output (iwas double-escape)
$userInitial = htmlspecialchars(mb_strtoupper(mb_substr(trim($userName), 0, 1)), ENT_QUOTES, 'UTF-8');

$uploadSuccess = $_SESSION['flash_success'] ?? '';
$uploadError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}
function columnRequiresValue($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return $row['is_nullable'] === 'NO' && $row['column_default'] === null;
    } catch (PDOException $e) { return false; }
}
function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function formatCurrency($amount) { return '₱' . number_format((float)$amount, 2); }
function getPaymentMethodBadge($method) {
    $method = strtolower(trim($method));
    switch ($method) {
        case 'cash': case 'over-the-counter (treasurer)':
            return '<span class="status-badge active"><i class="fa-solid fa-money-bill me-1"></i> Cash</span>';
        case 'bank': case 'online bank transfer':
            return '<span class="status-badge active" style="background:#fee2e2; color:#dc2626;"><i class="fa-solid fa-building-columns me-1"></i> Bank</span>';
        case 'online': case 'gcash': case 'maya': case 'e-wallet':
            return '<span class="status-badge active" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-wallet me-1"></i> E-Wallet</span>';
        default:
            return '<span class="status-badge pending">' . h($method) . '</span>';
    }
}

$refColumnName = 'reference_no';
if (!columnExists($conn, 'payments', 'reference_no')) {
    if (columnExists($conn, 'payments', 'reference_number')) $refColumnName = 'reference_number';
    elseif (columnExists($conn, 'payments', 'or_number')) $refColumnName = 'or_number';
}

/* Fallback kung wala pang safe_error() sa config */
if (!function_exists('safe_error')) {
    function safe_error($e) {
        error_log('[payments] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

/* Fallback content check kung wala sa config (MIME check gamit ang finfo) */
if (!function_exists('upload_content_ok')) {
    function upload_content_ok($tmp, $ext) {
        $map = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'pdf' => ['application/pdf']];
        if (!isset($map[$ext]) || !function_exists('finfo_open') || !is_file($tmp)) return false;
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $m = finfo_file($f, $tmp);
        finfo_close($f);
        return in_array($m, $map[$ext], true);
    }
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function requirePostCsrf() {
    header('Content-Type: application/json; charset=utf-8');
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
}

/* PAYMENT CHANNEL DETAILS
   Palitan ng tunay na detalye ng LGU. Para hindi na galawin ang code, gumawa ng
   config/payment_channels.php na nagre-return ng array na may parehong keys. */
$payCfg = [
    'gcash_name'      => 'LGU RENTRACK TREASURY',
    'gcash_no'        => '0917-123-4567',
    'gcash_qr'        => '../assets/img/lgu_gcash_qr.png',
    'bank_name'       => 'Land Bank of the Philippines',
    'bank_acct_name'  => 'LGU GENERAL FUND',
    'bank_acct_no'    => '1234-5678-90',
    'office_location' => 'Ground Floor, Municipal Hall',
    'office_window'   => 'Window 3 - Revenue Collection',
];
$payCfgFile = dirname(__DIR__) . '/config/payment_channels.php';
if (is_file($payCfgFile)) {
    $payCfgOverride = include $payCfgFile;
    if (is_array($payCfgOverride)) $payCfg = array_merge($payCfg, $payCfgOverride);
}

function methodLabel($method) {
    $m = strtolower(trim((string)$method));
    if (in_array($m, ['gcash', 'maya', 'online', 'e-wallet'], true)) return 'E-Wallet (GCash / Maya)';
    if (in_array($m, ['bank', 'online bank transfer'], true)) return 'Online Bank Transfer';
    if (in_array($m, ['cash', 'over-the-counter (treasurer)'], true)) return 'Over-the-Counter Cash';
    return $m !== '' ? ucfirst($m) : 'N/A';
}

/* Abisuhan ang staff kapag may bagong isinumiteng payment proof */
function notifyStaffNewPayment($conn, $ownerName, $bizName, $amount, $ref) {
    try {
        if (!tableExists($conn, 'notifications') || !columnExists($conn, 'users', 'role')) return;
        $ids = $conn->query("SELECT id FROM users WHERE LOWER(role::text) IN ('staff', 'admin', 'administrator', 'treasurer')")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) return;

        $hasLink = columnExists($conn, 'notifications', 'link');
        $hasTarget = columnExists($conn, 'notifications', 'target_url');
        $linkCol = $hasLink ? 'link' : ($hasTarget ? 'target_url' : null);
        $hasIsRead = columnExists($conn, 'notifications', 'is_read');
        $hasCreated = columnExists($conn, 'notifications', 'created_at');

        $title = 'New Payment Proof Submitted';
        $message = $ownerName . ' submitted a payment of ₱' . number_format((float)$amount, 2) . ' for ' . $bizName . ' (Ref: ' . $ref . '). Please verify.';
        foreach ($ids as $sid) {
            $cols = ['user_id', 'title', 'message'];
            $places = [':uid', ':title', ':msg'];
            $params = [':uid' => (int)$sid, ':title' => $title, ':msg' => $message];
            if ($linkCol) { $cols[] = $linkCol; $places[] = ':link'; $params[':link'] = 'payments.php'; }
            if ($hasIsRead) { $cols[] = 'is_read'; $places[] = 'FALSE'; }
            if ($hasCreated) { $cols[] = 'created_at'; $places[] = 'NOW()'; }
            $conn->prepare('INSERT INTO notifications (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $places) . ')')->execute($params);
        }
    } catch (PDOException $e) {
        error_log('[payments] notify staff: ' . $e->getMessage());
    }
}

/* MARK NOTIF (POST + CSRF) */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'mark_notif_read') {
    requirePostCsrf();
    $notifId = (int) ($_POST['id'] ?? 0);
    if ($userId > 0 && $notifId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtUpdate = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :nid AND user_id = :uid");
            $stmtUpdate->execute([':nid' => $notifId, ':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => safe_error($e)]); }
    } else { echo json_encode(['success' => false]); }
    exit;
}

/* CLEAR NOTIFS */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'clear_notifs') {
    requirePostCsrf();
    if ($userId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtDelete = $conn->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmtDelete->execute([':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => safe_error($e)]); }
    } else { echo json_encode(['success' => false]); }
    exit;
}

/* AI ASSISTANT */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'ai_chat') {
    header('Content-Type: application/json; charset=utf-8');
    $question = trim(strtolower($_GET['msg'] ?? ''));
    $totalRentals = 0; $activeRentals = 0; $totalPaid = 0; $pendingCount = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();
        if (columnExists($conn, 'rentals', 'status')) {
            $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
            $stmtActive->execute([':uid' => $userId]);
            $activeRentals = (int)$stmtActive->fetchColumn();
        }
    }
    if (tableExists($conn, 'payments') && tableExists($conn, 'rentals')) {
        $stmtPaid = $conn->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE r.user_id = :uid AND (LOWER(p.status::text) = 'paid' OR LOWER(p.status::text) = 'verified' OR LOWER(p.status::text) = 'completed')");
        $stmtPaid->execute([':uid' => $userId]);
        $totalPaid = (float)$stmtPaid->fetchColumn();

        $stmtPending = $conn->prepare("SELECT COUNT(*) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE r.user_id = :uid AND LOWER(p.status::text) = 'pending'");
        $stmtPending->execute([':uid' => $userId]);
        $pendingCount = (int)$stmtPending->fetchColumn();
    }
    $has = function (array $words) use ($question) {
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/u', $question)) return true;
        }
        return false;
    };
    $reply = "Hello! I am Rentrack AI. You can ask me about your payments, rentals, permits, or inspections.";
    if ($has(['how to pay', 'channel', 'where to pay', 'gcash', 'bank', 'paano'])) {
        $reply = "You can pay via GCash/Maya, Online Bank Transfer, or Over-the-Counter Cash at the Municipal Treasurer's Office. After paying, upload your receipt using the Upload Payment Proof button.";
    } elseif ($has(['pending', 'verification', 'verify'])) {
        $reply = "You have {$pendingCount} payment(s) pending verification by the LGU Treasury.";
    } elseif ($has(['payment', 'paid', 'bayad', 'magkano', 'pera'])) {
        $reply = "Your total verified payments received is ₱" . number_format($totalPaid, 2) . ". You have {$pendingCount} payment(s) awaiting verification.";
    } elseif ($has(['rental', 'property', 'total', 'ilan'])) {
        $reply = "You currently have {$totalRentals} registered rentals, with {$activeRentals} active properties.";
    } elseif ($has(['hello', 'hi', 'hey', 'kumusta'])) {
        $reply = "Hello! How can I help you today?";
    } else {
        $reply = "You have {$totalRentals} rentals and ₱" . number_format($totalPaid, 2) . " in verified payments.";
    }
    echo json_encode(['reply' => $reply]);
    exit;
}

/* AJAX SEARCH */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2 && tableExists($conn, 'rentals')) {
        $searchTerm = '%' . addcslashes($query, '%_\\') . '%';
        $sql = "SELECT id, tracking_no, business_name, status, rental_name FROM rentals 
                WHERE user_id = :uid AND is_deleted = '0' 
                  AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3) LIMIT 5";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':uid'=>$userId, ':q1'=>$searchTerm, ':q2'=>$searchTerm, ':q3'=>$searchTerm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            echo '<div style="display:flex; flex-direction:column; gap:8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(220,38,38,0.1)'; $badgeColor = '#dc2626';
                if (strtolower($status)==='pending') { $badgeBg='rgba(217,119,6,0.15)'; $badgeColor='#d97706'; }
                elseif (in_array(strtolower($status), ['approved','active'])) { $badgeBg='rgba(5,150,105,0.15)'; $badgeColor='#059669'; }
                echo '<a href="view_rental.php?id='.$row['id'].'" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#0f172a; text-decoration:none; gap:10px;">';
                echo '<div style="display:flex; flex-direction:column; gap:4px; min-width:0; flex:1;">';
                echo '<div style="font-weight:700; font-size:13px; color:#dc2626; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">'.htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']).' <span style="color:#94a3b8;">•</span> '.htmlspecialchars($row['business_name'] ?: $row['rental_name']).'</div>';
                echo '<div style="font-size:11px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Property: '.htmlspecialchars($row['rental_name'] ?? 'N/A').'</div>';
                echo '</div>';
                echo '<span style="padding:4px 10px; border-radius:20px; font-size:10px; font-weight:700; background:'.$badgeBg.'; color:'.$badgeColor.'; text-transform:uppercase; flex-shrink:0;">'.htmlspecialchars($status).'</span>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align:center; padding:30px; color:#64748b; font-size:13px;"><i class="fa-solid fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block; opacity:0.5;"></i> No matching rental records found.</div>';
        }
    }
    exit;
}

/* PAYMENT PROOF UPLOAD */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment_proof'])) {
    $rentalId       = (int)($_POST['rental_id'] ?? 0);
    $paymentPurpose = trim($_POST['payment_purpose'] ?? 'Permit Renewal');
    $renewalId      = (int)($_POST['renewal_id'] ?? 0);
    $amountRaw      = str_replace(',', '', trim($_POST['amount_paid'] ?? ''));
    $paymentMethod  = strtolower(trim($_POST['payment_method'] ?? ''));
    $referenceNo    = trim($_POST['reference_no'] ?? '');
    $paymentDate    = trim($_POST['payment_date'] ?? '');

    $allowedPurposes = ['Permit Renewal', 'New Registration', 'Inspection Fee', 'Penalty / Fine', 'Others'];
    $allowedMethods  = ['gcash', 'bank', 'cash'];
    $err = '';
    $rentalRow = null;
    $destination = '';
    $dbFilePath = '';

    /* 1) CSRF + basic validation */
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'Your session expired. Please refresh the page and try again.';
    } elseif ($rentalId <= 0 || $amountRaw === '' || $paymentMethod === '' || $referenceNo === '' || $paymentDate === '') {
        $err = 'Please fill in all required payment details.';
    } elseif (!in_array($paymentPurpose, $allowedPurposes, true) || !in_array($paymentMethod, $allowedMethods, true)) {
        $err = 'Invalid payment purpose or method selected.';
    } elseif (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 1000000) {
        $err = 'Please enter a valid amount greater than zero (maximum ₱1,000,000).';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ ]{3,59}$/', $referenceNo)) {
        $err = 'Reference / OR number must be 4 to 60 characters (letters, numbers, dash or slash only).';
    } else {
        $dtPay = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dtPay || $dtPay->format('Y-m-d') !== $paymentDate) {
            $err = 'Please enter a valid payment date.';
        } elseif ($paymentDate > date('Y-m-d')) {
            $err = 'Payment date cannot be in the future.';
        } elseif ($paymentDate < date('Y-m-d', strtotime('-1 year'))) {
            $err = "Payment date is too old. Please contact the Treasurer's Office.";
        }
    }

    /* 2) File presence / size */
    if ($err === '') {
        $fileErr = $_FILES['proof_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!isset($_FILES['proof_file']) || $fileErr === UPLOAD_ERR_NO_FILE) {
            $err = 'Please attach a screenshot or scanned copy of your payment proof.';
        } elseif ($fileErr === UPLOAD_ERR_INI_SIZE || $fileErr === UPLOAD_ERR_FORM_SIZE || ($_FILES['proof_file']['size'] ?? 0) > 5 * 1024 * 1024) {
            $err = 'File exceeds the maximum 5MB size limit.';
        } elseif ($fileErr !== UPLOAD_ERR_OK) {
            $err = 'File upload failed. Please try again.';
        }
    }

    /* 3) OWNERSHIP ng rental */
    if ($err === '') {
        try {
            $checkOwner = $conn->prepare("SELECT id, business_name, rental_name, tracking_no FROM rentals WHERE id = :rid AND user_id = :uid AND is_deleted = '0' LIMIT 1");
            $checkOwner->execute([':rid' => $rentalId, ':uid' => $userId]);
            $rentalRow = $checkOwner->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) $err = 'Invalid rental property selected.';
        } catch (PDOException $e) {
            error_log('[payments] ownership check: ' . $e->getMessage());
            $err = 'Unable to verify the selected property. Please try again.';
        }
    }

    /* 4) Renewal link: dapat sa kanya, sa parehong rental, at pending pa */
    if ($err === '') {
        if ($paymentPurpose !== 'Permit Renewal') $renewalId = 0;
        if (tableExists($conn, 'permit_renewals')) {
            try {
                if ($renewalId > 0) {
                    $chkRen = $conn->prepare("SELECT id FROM permit_renewals WHERE id = :id AND user_id = :uid AND rental_id = :rid AND LOWER(status::text) = 'pending' LIMIT 1");
                    $chkRen->execute([':id' => $renewalId, ':uid' => $userId, ':rid' => $rentalId]);
                    if (!$chkRen->fetch()) $err = 'The selected renewal request is invalid or is no longer pending.';
                } elseif ($paymentPurpose === 'Permit Renewal') {
                    $cntRen = $conn->prepare("SELECT COUNT(*) FROM permit_renewals WHERE user_id = :uid AND rental_id = :rid AND LOWER(status::text) = 'pending'");
                    $cntRen->execute([':uid' => $userId, ':rid' => $rentalId]);
                    if ((int)$cntRen->fetchColumn() > 0) $err = 'Please link this payment to your pending renewal request for this property.';
                }
            } catch (PDOException $e) {
                error_log('[payments] renewal check: ' . $e->getMessage());
                $err = 'Unable to verify the renewal request. Please try again.';
            }
        } else {
            $renewalId = 0;
        }
    }

    /* 5) DUPLICATE reference (buong sistema, hindi lang per rental). Pwede ulit kung na-reject/cancel na */
    if ($err === '') {
        try {
            $dupCols = [$refColumnName];
            if ($refColumnName !== 'or_number' && columnExists($conn, 'payments', 'or_number')) $dupCols[] = 'or_number';
            $conds = []; $dupParams = [];
            foreach ($dupCols as $i => $c) {
                $conds[] = "LOWER($c::text) = LOWER(:ref$i)";
                $dupParams[":ref$i"] = $referenceNo;
            }
            $dupSql = "SELECT id FROM payments WHERE (" . implode(' OR ', $conds) . ") AND LOWER(status::text) NOT IN ('rejected', 'cancelled')"
                    . (columnExists($conn, 'payments', 'is_voided') ? " AND (is_voided = FALSE OR is_voided IS NULL)" : "") . " LIMIT 1";
            $checkDuplicate = $conn->prepare($dupSql);
            $checkDuplicate->execute($dupParams);
            if ($checkDuplicate->fetch()) $err = 'This payment reference number or OR number has already been submitted.';
        } catch (PDOException $e) {
            error_log('[payments] duplicate check: ' . $e->getMessage());
            $err = 'Unable to validate the reference number. Please try again.';
        }
    }

    /* 6) File upload: LANG kapag pasado na ang lahat ng checks */
    if ($err === '') {
        $fileTmp = $_FILES['proof_file']['tmp_name'];
        $fileExt = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'pdf'], true) || !upload_content_ok($fileTmp, $fileExt)) {
            $err = 'Invalid file format. Only JPG, PNG, and PDF are allowed.';
        } else {
            $uploadDir = dirname(__DIR__) . '/uploads/payments/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $newFileName = 'PAY_' . $rentalId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
            $destination = $uploadDir . $newFileName;
            $dbFilePath = 'uploads/payments/' . $newFileName;
            if (!move_uploaded_file($fileTmp, $destination)) {
                $err = 'Failed to upload payment receipt file.';
            }
        }
    }

    /* 7) SAVE */
    if ($err === '') {
        try {
            $amountDb = number_format((float)$amountRaw, 2, '.', '');
            $cols   = ['rental_id', 'amount_paid', 'payment_method', $refColumnName, 'payment_date', 'proof_image', 'status', 'created_at'];
            $places = [':rid', ':amount', ':method', ':ref', ':pdate', ':proof', "'Pending'", 'NOW()'];
            $params = [
                ':rid' => $rentalId, ':amount' => $amountDb, ':method' => $paymentMethod,
                ':ref' => $referenceNo, ':pdate' => $paymentDate, ':proof' => $dbFilePath
            ];
            if (columnExists($conn, 'payments', 'user_id')) {
                $cols[] = 'user_id'; $places[] = ':uid'; $params[':uid'] = $userId;
            }
            if (columnExists($conn, 'payments', 'payment_purpose')) {
                $cols[] = 'payment_purpose'; $places[] = ':purpose'; $params[':purpose'] = $paymentPurpose;
            }
            if ($renewalId > 0 && columnExists($conn, 'payments', 'renewal_id')) {
                $cols[] = 'renewal_id'; $places[] = ':ren_id'; $params[':ren_id'] = $renewalId;
            }
            if ($refColumnName !== 'or_number' && columnExists($conn, 'payments', 'or_number') && columnRequiresValue($conn, 'payments', 'or_number')) {
                $cols[] = 'or_number'; $places[] = ':orno'; $params[':orno'] = $referenceNo;
            }
            $insertStmt = $conn->prepare('INSERT INTO payments (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $places) . ')');
            $insertStmt->execute($params);

            $bizLabel = !empty($rentalRow['business_name']) ? $rentalRow['business_name'] : ($rentalRow['rental_name'] ?? 'a property');
            notifyStaffNewPayment($conn, $userName, $bizLabel, $amountDb, $referenceNo);

            $_SESSION['flash_success'] = "Payment proof submitted successfully! Awaiting LGU Treasury verification. You will be notified once it is verified.";
        } catch (PDOException $e) {
            if ($destination !== '' && is_file($destination)) @unlink($destination);   // iwas ulila na file
            error_log('[payments] insert: ' . $e->getMessage());
            $err = 'We could not save your payment. Please try again.';
        }
    }

    if ($err !== '') $_SESSION['flash_error'] = $err;
    header("Location: payments.php");
    exit();
}

/* User rentals */
$userRentals = [];
$stmtRentals = $conn->prepare("SELECT id, tracking_no, business_name, rental_name FROM rentals WHERE user_id = :uid AND is_deleted = '0' ORDER BY business_name ASC");
$stmtRentals->execute([':uid' => $userId]);
$userRentals = $stmtRentals->fetchAll(PDO::FETCH_ASSOC);

/* Pending renewals */
$userPendingRenewals = [];
if (tableExists($conn, 'permit_renewals')) {
    try {
        $stmtRenewals = $conn->prepare("SELECT pr.id, pr.rental_id, pr.document_type, r.business_name, r.tracking_no FROM permit_renewals pr JOIN rentals r ON pr.rental_id = r.id WHERE pr.user_id = :uid AND LOWER(pr.status::text) = 'pending' ORDER BY pr.id DESC");
        $stmtRenewals->execute([':uid' => $userId]);
        $userPendingRenewals = $stmtRenewals->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $userPendingRenewals = []; }
}

/* Notifications */
$unreadNotifCount = 0; $notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE LOWER(is_read::text) IN ('0', 'false', 'f') AND user_id = :uid");
            $stmtNotifCount->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $stmtNotifCount->fetchColumn();
        } catch (PDOException $e) { $unreadNotifCount = 0; }
    }
    $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $notifStmt->execute([':uid' => $userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ==============================================
   PAYMENT HISTORY: filters, search, pagination
============================================== */
$SQL_VERIFIED = "LOWER(p.status::text) IN ('paid', 'verified', 'completed')";
$SQL_PENDING  = "LOWER(p.status::text) = 'pending'";
$SQL_REJECTED = "LOWER(p.status::text) IN ('rejected', 'cancelled')";
$voidSql      = columnExists($conn, 'payments', 'is_voided') ? " AND (p.is_voided = FALSE OR p.is_voided IS NULL)" : "";
$hasOrCol     = columnExists($conn, 'payments', 'or_number');
$hasVerifiedAt = columnExists($conn, 'payments', 'verified_at');
$rejectCol = null;
foreach (['reject_reason', 'rejection_reason', 'rejected_reason', 'remarks'] as $cand) {
    if (columnExists($conn, 'payments', $cand)) { $rejectCol = $cand; break; }
}

$getStr = function ($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : ''; };
$fStatus = in_array($getStr('status'), ['verified', 'pending', 'rejected'], true) ? $getStr('status') : 'all';
$fMethod = in_array($getStr('method'), ['gcash', 'bank', 'cash'], true) ? $getStr('method') : 'all';
$fMonth  = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $getStr('month')) ? $getStr('month') : '';
$fQuery  = mb_substr(trim($getStr('q')), 0, 60);
$fRental = (int)$getStr('rental');
$page    = max(1, (int)$getStr('page'));

$where = "r.user_id = :uid" . $voidSql;
$fp = [':uid' => $userId];
if ($fStatus === 'verified') $where .= " AND $SQL_VERIFIED";
elseif ($fStatus === 'pending') $where .= " AND $SQL_PENDING";
elseif ($fStatus === 'rejected') $where .= " AND $SQL_REJECTED";

$methodMap = [
    'gcash' => "('gcash', 'maya', 'online', 'e-wallet')",
    'bank'  => "('bank', 'online bank transfer')",
    'cash'  => "('cash', 'over-the-counter (treasurer)')",
];
if ($fMethod !== 'all') $where .= " AND LOWER(p.payment_method::text) IN " . $methodMap[$fMethod];
if ($fMonth !== '') { $where .= " AND TO_CHAR(p.payment_date, 'YYYY-MM') = :fmonth"; $fp[':fmonth'] = $fMonth; }
if ($fRental > 0)   { $where .= " AND p.rental_id = :frental"; $fp[':frental'] = $fRental; }
if ($fQuery !== '') {
    $like = '%' . addcslashes($fQuery, '%_\\') . '%';
    $conds = ["p.$refColumnName::text ILIKE :q1", "r.tracking_no ILIKE :q2", "r.business_name ILIKE :q3", "r.rental_name ILIKE :q4"];
    $fp[':q1'] = $fp[':q2'] = $fp[':q3'] = $fp[':q4'] = $like;
    if ($hasOrCol && $refColumnName !== 'or_number') { $conds[] = "p.or_number::text ILIKE :q5"; $fp[':q5'] = $like; }
    $where .= " AND (" . implode(' OR ', $conds) . ")";
}
$hasActiveFilters = ($fStatus !== 'all' || $fMethod !== 'all' || $fMonth !== '' || $fQuery !== '' || $fRental > 0);

$baseParams = array_filter([
    'status' => $fStatus !== 'all' ? $fStatus : '',
    'method' => $fMethod !== 'all' ? $fMethod : '',
    'month'  => $fMonth,
    'q'      => $fQuery,
    'rental' => $fRental > 0 ? $fRental : '',
], function ($v) { return $v !== '' && $v !== null; });
$buildUrl = function (array $extra = []) use ($baseParams) {
    $qs = http_build_query(array_merge($baseParams, $extra));
    return 'payments.php' . ($qs !== '' ? '?' . $qs : '');
};

/* CSV EXPORT (sumusunod sa kasalukuyang filters) */
if ($getStr('export') === 'csv') {
    $csvSafe = function ($v) {
        $v = (string)$v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // iwas CSV/Excel formula injection
    };
    try {
        $stmtX = $conn->prepare("SELECT p.*, r.business_name, r.rental_name, r.tracking_no FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE $where ORDER BY p.payment_date DESC, p.id DESC LIMIT 5000");
        $stmtX->execute($fp);
        $rowsX = $stmtX->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[payments] export: ' . $e->getMessage());
        $rowsX = [];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference / OR No.', 'Business / Property', 'Tracking No.', 'Purpose', 'Amount (PHP)', 'Payment Date', 'Method', 'Status', 'Submitted At']);
    foreach ($rowsX as $x) {
        $refX = !empty($x['or_number']) ? $x['or_number'] : ($x[$refColumnName] ?? '');
        fputcsv($out, [
            $csvSafe($refX),
            $csvSafe(!empty($x['business_name']) ? $x['business_name'] : ($x['rental_name'] ?? '')),
            $csvSafe($x['tracking_no'] ?? ''),
            $csvSafe($x['payment_purpose'] ?? ''),
            number_format((float)($x['amount_paid'] ?? 0), 2, '.', ''),
            !empty($x['payment_date']) ? date('Y-m-d', strtotime($x['payment_date'])) : '',
            methodLabel($x['payment_method'] ?? ''),
            ucfirst(strtolower((string)($x['status'] ?? ''))),
            !empty($x['created_at']) ? date('Y-m-d H:i', strtotime($x['created_at'])) : '',
        ]);
    }
    fclose($out);
    exit;
}

$perPage = 10;
$totalRows = 0;
try {
    $stmtCnt = $conn->prepare("SELECT COUNT(*) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE $where");
    $stmtCnt->execute($fp);
    $totalRows = (int)$stmtCnt->fetchColumn();
} catch (PDOException $e) { error_log('[payments] count: ' . $e->getMessage()); }
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$payments = [];
try {
    $stmtList = $conn->prepare("SELECT p.*, r.business_name, r.rental_name, r.tracking_no FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE $where ORDER BY p.payment_date DESC, p.id DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
    $stmtList->execute($fp);
    $payments = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[payments] list: ' . $e->getMessage()); }
$showFrom = $totalRows > 0 ? $offset + 1 : 0;
$showTo   = min($offset + $perPage, $totalRows);

/* ==============================================
   SUMMARY (hindi apektado ng filters)
============================================== */
$totalPayments = 0; $verifiedCount = 0; $totalAmount = 0.0; $pendingCount = 0; $pendingAmount = 0.0; $rejectedCount = 0;
$thisMonthAmt = 0.0; $lastMonthAmt = 0.0; $momPct = null;
try {
    $stmtSum = $conn->prepare("
        SELECT COUNT(*) AS cnt,
               COUNT(*) FILTER (WHERE $SQL_VERIFIED) AS vcnt,
               COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_VERIFIED), 0) AS vamt,
               COUNT(*) FILTER (WHERE $SQL_PENDING) AS pcnt,
               COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_PENDING), 0) AS pamt,
               COUNT(*) FILTER (WHERE $SQL_REJECTED) AS rcnt,
               COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_VERIFIED AND DATE_TRUNC('month', p.payment_date) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS this_m,
               COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_VERIFIED AND DATE_TRUNC('month', p.payment_date) = DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month'), 0) AS last_m
        FROM payments p INNER JOIN rentals r ON p.rental_id = r.id
        WHERE r.user_id = :uid $voidSql
    ");
    $stmtSum->execute([':uid' => $userId]);
    $sum = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalPayments = (int)($sum['cnt'] ?? 0);
    $verifiedCount = (int)($sum['vcnt'] ?? 0);
    $totalAmount   = (float)($sum['vamt'] ?? 0);
    $pendingCount  = (int)($sum['pcnt'] ?? 0);
    $pendingAmount = (float)($sum['pamt'] ?? 0);
    $rejectedCount = (int)($sum['rcnt'] ?? 0);
    $thisMonthAmt  = (float)($sum['this_m'] ?? 0);
    $lastMonthAmt  = (float)($sum['last_m'] ?? 0);
    if ($lastMonthAmt > 0) $momPct = (($thisMonthAmt - $lastMonthAmt) / $lastMonthAmt) * 100;
} catch (PDOException $e) { error_log('[payments] summary: ' . $e->getMessage()); }

/* CHART DATA: verified payments per month (6 months) */
$chartMonthLabels = []; $chartMonthValues = []; $hasMonthlyData = false;
try {
    $stmtM = $conn->prepare("
        SELECT TO_CHAR(p.payment_date, 'YYYY-MM') AS mk, COALESCE(SUM(p.amount_paid), 0) AS total
        FROM payments p INNER JOIN rentals r ON p.rental_id = r.id
        WHERE r.user_id = :uid $voidSql AND $SQL_VERIFIED
          AND p.payment_date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
        GROUP BY TO_CHAR(p.payment_date, 'YYYY-MM')
    ");
    $stmtM->execute([':uid' => $userId]);
    $mMap = [];
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $mr) $mMap[$mr['mk']] = (float)$mr['total'];
} catch (PDOException $e) { $mMap = []; }
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $chartMonthLabels[] = date('M', $ts);
    $v = $mMap[date('Y-m', $ts)] ?? 0;
    $chartMonthValues[] = $v;
    if ($v > 0) $hasMonthlyData = true;
}

/* CHART DATA: verified amount per payment method */
$chartMethodLabels = []; $chartMethodValues = []; $hasMethodData = false;
try {
    $stmtMt = $conn->prepare("
        SELECT CASE
                 WHEN LOWER(p.payment_method::text) IN ('gcash', 'maya', 'online', 'e-wallet') THEN 'E-Wallet'
                 WHEN LOWER(p.payment_method::text) IN ('bank', 'online bank transfer') THEN 'Bank Transfer'
                 WHEN LOWER(p.payment_method::text) IN ('cash', 'over-the-counter (treasurer)') THEN 'Cash'
                 ELSE 'Other' END AS m,
               COALESCE(SUM(p.amount_paid), 0) AS total
        FROM payments p INNER JOIN rentals r ON p.rental_id = r.id
        WHERE r.user_id = :uid $voidSql AND $SQL_VERIFIED
        GROUP BY 1 ORDER BY total DESC
    ");
    $stmtMt->execute([':uid' => $userId]);
    foreach ($stmtMt->fetchAll(PDO::FETCH_ASSOC) as $mt) {
        $chartMethodLabels[] = $mt['m'];
        $chartMethodValues[] = (float)$mt['total'];
        if ((float)$mt['total'] > 0) $hasMethodData = true;
    }
} catch (PDOException $e) { /* walang chart */ }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Payments &amp; Where to Pay | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-light: #f8fafc; --bg-card: #ffffff; --card-border: #e2e8f0;
            --accent-blue: #dc2626; --accent-blue-dark: #991b1b; --accent-blue-light: #f87171;
            --blue-soft: #fee2e2;
            --text-primary: #0f172a; --text-secondary: #334155;
            --text-muted: #64748b; --text-faint: #94a3b8;
            --ok: #059669; --ok-bg: #d1fae5;
            --warn: #d97706; --warn-bg: #fef3c7;
            --danger: #dc2626; --danger-bg: #fee2e2;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important;
            height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-light); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
        }
        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.4) transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .main-wrapper {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            background: var(--bg-light);
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group { display: flex; align-items: center; gap: 12px; color: #fecdd3; font-size: 11px; font-weight: 600; }
        .official-header-badge {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);
            padding: 2px 10px; border-radius: 20px;
            display: flex; align-items: center; gap: 6px; color: #ffffff;
        }
        .oh-title { display: flex; align-items: center; gap: 8px; }
        .oh-sub { font-weight: 400; opacity: 0.9; }

        @media (max-width: 992px) { .oh-city { display: none; } }
        @media (max-width: 768px) {
            .official-header {
                display: grid; grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas: "title badge" "sub sub";
                gap: 3px 8px; padding: 6px 12px; align-items: center;
            }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }

        /* STICKY HEADER */
        .sticky-header { position: sticky; top: 0; z-index: 100; background: #ffffff; }
        .top-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 18px; background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            gap: 14px; flex-wrap: nowrap; min-height: 62px;
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring {
            position: absolute; inset: -2px; border-radius: 50%;
            border: 2px dashed rgba(220, 38, 38, 0.55);
            animation: spinRing 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--accent-blue); line-height: 1.1; margin: 0; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.3px; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none; width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f8fafc; border: 1px solid var(--card-border);
            color: var(--accent-blue); cursor: pointer; font-size: 16px; flex-shrink: 0;
        }

        /* NAV */
        .main-nav-bar {
            display: flex; align-items: center;
            background: transparent; border: none; padding: 0;
            position: relative; flex-shrink: 0; z-index: 45;
        }
        .nav-menu { list-style: none; margin: 0; padding: 0; display: flex; align-items: center; gap: 2px; flex-wrap: nowrap; }
        .nav-item { position: relative; }
        .nav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 12px; color: #334155; text-decoration: none;
            font-size: 12.5px; font-weight: 600; border-radius: 8px;
            transition: all 0.15s ease; cursor: pointer;
            white-space: nowrap; background: transparent;
            border: none; font-family: inherit;
        }
        .nav-link i.nav-ico { color: var(--accent-blue); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--accent-blue); background: var(--blue-soft); }
        .nav-link.active { color: var(--accent-blue); background: var(--blue-soft); font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--accent-blue); background: var(--blue-soft); }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-dropdown {
            position: absolute; top: calc(100% + 6px); left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 12px;
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.15);
            padding: 8px; opacity: 0; visibility: hidden;
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        .nav-item.align-right .nav-dropdown { left: auto; right: 0; transform: translateX(0) translateY(8px); }
        .nav-item.align-right.open .nav-dropdown { transform: translateX(0) translateY(0); }
        .nav-dropdown a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 8px;
            color: #334155; text-decoration: none; font-size: 13px; font-weight: 500;
        }
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue); font-size: 14px; text-align: center; flex-shrink: 0; }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px; padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--accent-blue); z-index: 2; font-size: 13px; }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #0f172a; font-size: 13px; outline: none; cursor: pointer;
        }

        /* RIGHT */
        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--blue-soft); border: 1px solid var(--card-border);
        }
        .nav-clock .time { font-family: 'Space Grotesk', monospace; font-size: 13px; font-weight: 700; color: var(--accent-blue); }
        .nav-clock .date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid var(--card-border);
            color: var(--accent-blue); cursor: pointer;
            position: relative; flex-shrink: 0; text-decoration: none;
        }
        .nav-icon .badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--accent-blue); color: #fff;
            font-size: 9px; font-weight: 700;
            min-width: 18px; height: 18px; padding: 0 4px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #ffffff;
        }

        .profile-wrapper { position: relative; }
        .profile-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 3px 12px 3px 3px;
            border-radius: 12px; background: #ffffff;
            border: 1px solid var(--card-border); cursor: pointer;
        }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent-blue);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px;
        }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: #ffffff; border: 1px solid var(--card-border);
            border-radius: 14px; padding: 8px;
            box-shadow: 0 16px 36px rgba(220, 38, 38, 0.15);
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease; z-index: 60;
        }
        .profile-dropdown { width: 240px; }
        .notification-dropdown { width: 360px; max-width: calc(100vw - 24px); padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            color: #334155; text-decoration: none; font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .profile-dropdown .dropdown-item.danger { color: var(--danger); }
        .profile-dropdown .dropdown-item.danger:hover { background: var(--danger-bg); }

        .notification-title { padding: 14px 20px; font-weight: 700; font-size: 14px; border-bottom: 1px solid var(--blue-soft); color: #0f172a; }

        /* CONTENT */
        .content { flex: 1; padding: 22px 24px 40px; padding-bottom: calc(40px + var(--safe-bottom)); background: var(--bg-light); }

        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            box-shadow: 0 8px 22px rgba(220, 38, 38, 0.2); margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #fee2e2; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }
        .btn-hero {
            background: #ffffff; color: #dc2626;
            padding: 9px 18px; border-radius: 999px; font-weight: 800; font-size: 13px;
            cursor: pointer; transition: var(--transition); border: none;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .btn-hero:hover { background: #fee2e2; color: #dc2626; transform: translateY(-1px); }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px; }
        .stat-card {
            border-radius: 14px; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.03); cursor: pointer; transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.history { background: #fae8ff; border: 1px solid #f5d0fe; }
        .stat-card.collection { background: #e6fffa; border: 1px solid #b2f5ea; }
        .stat-card.balance { background: #fffbeb; border: 1px solid #fde68a; }
        .stat-label { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; }
        .stat-number { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #0f172a; }
        .stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .stat-card.history .stat-icon { background: #fae8ff; color: #a21caf; }
        .stat-card.collection .stat-icon { background: #ccfbf1; color: #0d9488; }
        .stat-card.balance .stat-icon { background: #fef3c7; color: #d97706; }

        /* CHANNELS */
        .channels-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .channel-card {
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 14px; padding: 16px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.03); transition: var(--transition);
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .channel-card:hover { border-color: var(--accent-blue); }
        .channel-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .channel-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--blue-soft); color: var(--accent-blue); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .channel-title { font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 800; color: #0f172a; margin: 0; }
        .channel-subtitle { font-size: 10.5px; color: var(--accent-blue-dark); font-weight: 700; text-transform: uppercase; }
        .channel-desc { font-size: 12px; color: #475569; margin-bottom: 12px; line-height: 1.4; }
        .channel-details { background: #f8fafc; border: 1px solid var(--card-border); border-radius: 8px; padding: 8px 10px; font-size: 11.5px; color: #334155; margin-bottom: 12px; }
        .channel-details div { display: flex; justify-content: space-between; margin-bottom: 3px; }

        .qr-code-container { background: #ffffff; border: 1px dashed var(--accent-blue); border-radius: 10px; padding: 8px; text-align: center; margin-bottom: 10px; }
        .qr-code-container img { width: 100px; height: 100px; object-fit: contain; border-radius: 6px; margin-bottom: 4px; }
        .qr-code-label { font-size: 10px; font-weight: 700; color: var(--accent-blue-dark); text-transform: uppercase; }

        .btn-upload {
            background: var(--accent-blue); color: #fff; border: none; padding: 8px 14px; border-radius: 10px;
            font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            width: 100%; transition: var(--transition);
        }
        .btn-upload:hover { background: var(--accent-blue-dark); color: #fff; }

        /* PANEL */
        .panel { background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04); overflow: hidden; margin-bottom: 20px; }
        .panel-header {
            padding: 14px 20px; background: #ffffff; border-bottom: 1px solid var(--card-border);
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-wrap: wrap;
        }
        .panel-body { padding: 20px; }

        /* TABLE */
        .table-responsive { overflow-x: auto; }
        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom th { background: #f8fafc; color: var(--accent-blue-dark); padding: 12px 16px; font-weight: 800; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid var(--card-border); text-align: left; white-space: nowrap; }
        .table-custom td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .table-custom tr:hover { background: #fef2f2; }

        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #dc2626; }

        .empty-state { text-align: center; padding: 38px 20px; color: #64748b; }
        .empty-state i { font-size: 32px; opacity: 0.5; display: block; margin-bottom: 8px; color: var(--accent-blue); }
        .empty-state p { font-size: 13px; margin: 0; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* FORM (Modal) */
        .form-label { font-size: 12.5px; color: #334155; font-weight: 700; }
        .form-control, .form-select {
            border: 1px solid var(--card-border); border-radius: 10px; padding: 10px 14px; font-size: 13.5px; color: #0f172a; background: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--accent-blue); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15); outline: none;
        }
        .text-danger { color: var(--danger) !important; }
        .text-muted { color: #64748b !important; }

        /* SEARCH MODAL */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(153, 27, 27, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding: 10vh 16px 16px;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }
        .search-modal-content {
            width: 100%; max-width: 650px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 20px;
            box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 85vh; display: flex; flex-direction: column;
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }
        .search-modal-header {
            display: flex; align-items: center; padding: 14px 18px;
            border-bottom: 1px solid var(--card-border); background: #f8fafc; flex-shrink: 0;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue); margin-right: 14px; }
        .search-modal-input { flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600; }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease; flex-shrink: 0;
        }
        .search-modal-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }
        .search-modal-body { padding: 16px; max-height: 380px; overflow-y: auto; flex: 1; }
        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 18px;
            background: #f8fafc; border-top: 1px solid var(--card-border); font-size: 11px; color: var(--text-muted); flex-shrink: 0;
        }
        .search-shortcut-hint kbd {
            background: var(--blue-soft); border: 1px solid var(--card-border); padding: 2px 6px; border-radius: 4px; color: var(--accent-blue-dark); font-family: inherit; font-weight: 700;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) { .nav-link { padding: 10px 8px; font-size: 12px; } }
        @media (max-width: 1200px) {
            .top-navbar { padding: 10px 14px; padding-top: calc(10px + var(--safe-top)); gap: 10px; flex-wrap: wrap; min-height: 60px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; }
            .menu-toggle { display: flex; }
            .search-wrapper { order: 5; width: 100%; padding: 0; margin: 4px 0; max-width: 100%; flex-basis: 100%; }

            .main-nav-bar {
                position: fixed; top: 0; left: -100%;
                width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh;
                flex-direction: column; align-items: stretch;
                justify-content: flex-start; padding: 0; z-index: 2000;
                overflow-y: auto; -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            }
            .main-nav-bar.mobile-open { left: 0 !important; }

            .mobile-drawer-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 18px; padding-top: calc(16px + var(--safe-top));
                background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
                color: #fff; flex-shrink: 0; border-bottom: 2px solid #7f1d1d;
            }
            .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
            .mobile-drawer-header .drawer-brand-logo {
                width: 32px; height: 32px; border-radius: 50%;
                background: #ffffff; color: var(--accent-blue);
                display: flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 12px;
            }
            .mobile-drawer-header .drawer-brand-text h2 { font-size: 13px; font-weight: 800; margin: 0; color: #fff; }
            .mobile-drawer-header .drawer-brand-text span { font-size: 9px; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
            .mobile-drawer-header .drawer-close-btn {
                width: 34px; height: 34px; border-radius: 8px;
                background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35);
                color: #fff; cursor: pointer; font-size: 15px;
                display: flex; align-items: center; justify-content: center;
            }
            .main-nav-bar .nav-menu {
                flex-direction: column; width: 100%;
                justify-content: flex-start; gap: 4px;
                padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            }
            .nav-item { width: 100%; }
            .nav-link { padding: 14px 16px; border-radius: 10px; justify-content: flex-start; font-size: 14.5px; min-height: 48px; }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            .nav-dropdown,
            .nav-item.open .nav-dropdown,
            .nav-item.align-right .nav-dropdown,
            .nav-item.align-right.open .nav-dropdown {
                position: static; left: auto; right: auto;
                transform: none; opacity: 1; visibility: visible;
                box-shadow: none; border: none;
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc; margin-top: 4px; border-radius: 10px; min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999;
                backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
            }
            .sidebar-overlay.active { display: block; opacity: 1; }
            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }

            .stats-grid { grid-template-columns: repeat(1, 1fr); }
            .channels-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 14px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .btn-hero { width: 100%; justify-content: center; }
            .panel-header { padding: 12px 16px; font-size: 14px; }
            .panel-body { padding: 14px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
        }
        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input, .form-control, .form-select { font-size: 16px !important; }
        }
        /* ===== PAYMENTS: NEW UI ===== */
        .steps-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
        .steps-strip .step { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--card-border); border-radius: 14px; padding: 12px 14px; }
        .steps-strip .step-no { width: 30px; height: 30px; border-radius: 50%; background: var(--blue-soft); color: var(--accent-blue-dark); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .steps-strip .step strong { display: block; font-size: 13px; color: #0f172a; }
        .steps-strip .step small { font-size: 11.5px; color: #64748b; }
        .mom-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 999px; margin-left: 6px; }
        .mom-chip.up { background: #d1fae5; color: #059669; }
        .mom-chip.down { background: #fee2e2; color: #dc2626; }
        .charts-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; }
        .chart-box { position: relative; height: 240px; }
        .chart-empty { position: absolute; inset: 0; z-index: 5; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,0.9); text-align: center; color: #64748b; font-size: 12.5px; font-weight: 600; }
        .chart-empty i { font-size: 26px; color: var(--accent-blue-light); margin-bottom: 8px; }
        .export-link { font-size: 12px; font-weight: 700; color: var(--accent-blue); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--card-border); border-radius: 999px; background: #fff; }
        .export-link:hover { background: var(--blue-soft); }
        .filter-bar { padding: 14px 20px; border-bottom: 1px solid var(--card-border); background: #f8fafc; }
        .filter-bar form { display: grid; grid-template-columns: 1.6fr repeat(4, 1fr) auto; gap: 10px; align-items: end; }
        .filter-bar label { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: #64748b; margin-bottom: 3px; display: block; }
        .filter-bar .form-control, .filter-bar .form-select { font-size: 12.5px; padding: 7px 10px; border-radius: 10px; }
        .filter-actions { display: flex; gap: 8px; }
        .btn-filter { background: var(--accent-blue); color: #fff; border: none; border-radius: 10px; font-size: 12.5px; font-weight: 700; padding: 8px 16px; cursor: pointer; }
        .btn-filter:hover { background: var(--accent-blue-dark); }
        .btn-reset { background: #fff; color: #475569; border: 1px solid var(--card-border); border-radius: 10px; font-size: 12.5px; font-weight: 700; padding: 8px 14px; text-decoration: none; display: inline-flex; align-items: center; }
        .row-sub { display: block; font-size: 10.5px; color: #94a3b8; margin-top: 2px; }
        .reject-note { display: block; margin-top: 6px; font-size: 11px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 5px 8px; max-width: 240px; white-space: normal; }
        .act-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .act-btn { border: 1px solid var(--card-border); background: #fff; color: #334155; font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .act-btn:hover { background: var(--blue-soft); border-color: var(--accent-blue-light); color: var(--accent-blue-dark); }
        .act-btn.resubmit { color: #b45309; border-color: #fde68a; background: #fffbeb; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 20px; border-top: 1px solid var(--card-border); flex-wrap: wrap; }
        .pager-info { font-size: 12px; color: #64748b; font-weight: 600; }
        .pager-links { display: flex; gap: 6px; }
        .pager-links a { font-size: 12px; font-weight: 700; padding: 5px 11px; border-radius: 8px; border: 1px solid var(--card-border); color: #334155; text-decoration: none; background: #fff; }
        .pager-links a:hover { background: var(--blue-soft); }
        .pager-links a.active { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }
        .copy-btn { border: none; background: var(--blue-soft); color: var(--accent-blue-dark); font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 999px; cursor: pointer; margin-left: 6px; }
        .qr-missing img { display: none; }
        .qr-missing-note { display: none; font-size: 11.5px; color: #64748b; padding: 10px; }
        .qr-missing .qr-missing-note { display: block; }
        .proof-frame { width: 100%; height: 60vh; border: 1px solid var(--card-border); border-radius: 10px; }
        .proof-img { display: block; margin: 0 auto; max-width: 100%; max-height: 55vh; width: auto; height: auto; object-fit: contain; border-radius: 10px; border: 1px solid var(--card-border); }
        .proof-open { display: inline-block; margin-top: 10px; font-size: 12px; font-weight: 700; color: var(--accent-blue); text-decoration: none; }
        .proof-open:hover { text-decoration: underline; }
        #proofModal .modal-dialog { max-width: 400px; }
        #proofModal .modal-dialog.is-pdf { max-width: 720px; }
        .receipt-head { text-align: center; border-bottom: 2px solid var(--accent-blue); padding-bottom: 10px; margin-bottom: 14px; }
        .receipt-brand { font-size: 20px; font-weight: 800; color: var(--accent-blue-dark); letter-spacing: .5px; }
        .receipt-sub { font-size: 12px; color: #64748b; font-weight: 600; }
        .receipt-table { width: 100%; font-size: 13px; }
        .receipt-table td { padding: 6px 0; border-bottom: 1px dashed #e2e8f0; vertical-align: top; }
        .receipt-table td:first-child { color: #64748b; width: 42%; }
        .receipt-table td:last-child { font-weight: 700; color: #0f172a; text-align: right; }
        .receipt-note { font-size: 10.5px; color: #94a3b8; margin: 14px 0 0; }
        @media (max-width: 992px) {
            .charts-row { grid-template-columns: 1fr; }
            .filter-bar form { grid-template-columns: 1fr 1fr; }
            .steps-strip { grid-template-columns: 1fr; }
        }
        @media print {
            body * { visibility: hidden !important; }
            #receiptPrintArea, #receiptPrintArea * { visibility: visible !important; }
            #receiptPrintArea { position: fixed; left: 0; top: 0; width: 100%; padding: 28px; background: #fff; }
        }
    </style>
</head>
<body>

    <!-- TOP OFFICIAL BAR -->
    <div class="official-header">
        <div class="official-header-left">
            <div class="oh-title">
                <i class="fa-solid fa-landmark"></i>
                <span>REPUBLIC OF THE PHILIPPINES</span>
            </div>
            <span class="oh-sep" style="opacity: 0.7;">|</span>
            <span class="oh-sub">Local Government Unit Property &amp; Rental Compliance Portal</span>
        </div>
        <div class="official-header-right-group">
            <div class="official-header-badge">
                <i class="fa-solid fa-certificate"></i>
                <span>LGU CERTIFIED <span class="oh-portal">PORTAL </span>V2.1</span>
            </div>
            <span class="oh-city">City Treasurer &amp; Rentrack Online System</span>
        </div>
    </div>

    <div class="main-wrapper">
        <div class="sticky-header">
            <header class="top-navbar">
                <div class="navbar-left">
                    <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
                    <div class="nav-brand">
                        <div class="brand-logo">
                            <div class="brand-logo-ring"></div>
                            <div class="brand-logo-circle">RT</div>
                        </div>
                        <div class="brand-text">
                            <h2>RENTRACK</h2>
                            <span>Owner Portal</span>
                        </div>
                    </div>
                </div>

                <nav class="main-nav-bar" id="mainNavBar">
                    <div class="mobile-drawer-header">
                        <div class="drawer-brand">
                            <div class="drawer-brand-logo">RT</div>
                            <div class="drawer-brand-text">
                                <h2>RENTRACK</h2>
                                <span>Owner Portal</span>
                            </div>
                        </div>
                        <button type="button" class="drawer-close-btn" id="drawerCloseBtn"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard</a>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-building nav-ico"></i> Rentals
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="rentals.php"><i class="fa-solid fa-building"></i> My Rentals</a>
                                <a href="register_rental.php"><i class="fa-solid fa-circle-plus"></i> Register Rental</a>
                                <a href="rental_requests.php"><i class="fa-solid fa-file-circle-check"></i> Rental Requests</a>
                                <a href="schedule.php"><i class="fa-solid fa-calendar-days"></i> Rental Schedule</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                                <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-check"></i> Inspection Schedule</a>
                                <a href="maintenance.php"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-file-lines nav-ico"></i> Documents
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                <a href="renew_documents.php"><i class="fa-solid fa-file-arrow-up"></i> Online Renewal</a>
                                <a href="compliance.php"><i class="fa-solid fa-file-shield"></i> Compliance</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown align-right">
                            <button type="button" class="nav-link active" data-dropdown>
                                <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                                <a href="reports.php"><i class="fa-solid fa-chart-simple"></i> Reports</a>
                            </div>
                        </li>
                    </ul>
                </nav>

                <div class="search-wrapper">
                    <div class="search-container" onclick="openSearchModal()">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="Search tracking no, business name..." readonly style="cursor: pointer;">
                    </div>
                </div>

                <div class="navbar-right">
                    <div class="nav-clock">
                        <div class="time" id="liveTime"><?php echo date('h:i:s A'); ?></div>
                        <div class="date"><?php echo date('M d, Y'); ?></div>
                    </div>

                    <div class="nav-icon-wrapper" style="position:relative;">
                        <button class="nav-icon" id="notifBtn" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unreadNotifCount > 0): ?>
                                <span class="badge" id="notifBadgeCount"><?php echo $unreadNotifCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notifDropdown" class="notification-dropdown">
                            <div class="notification-title" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <span>Notifications</span>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?php if ($unreadNotifCount > 0): ?>
                                        <span id="notifHeaderCount" style="font-size:10px; background:var(--blue-soft); color:var(--accent-blue-dark); padding:2px 8px; border-radius:10px;"><?php echo $unreadNotifCount; ?> New</span>
                                    <?php endif; ?>
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
                                </div>
                            </div>
                            <div style="max-height:280px; overflow-y:auto;" id="notifListBody">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <?php
                                            $notifLink = 'payments.php';
                                            if (!empty($notification['link'])) {
                                                $notifLink = $notification['link'];
                                            } else {
                                                $titleLower = strtolower($notification['title'] ?? '');
                                                $msgLower = strtolower($notification['message'] ?? '');
                                                if (strpos($titleLower, 'rental') !== false || strpos($msgLower, 'rental') !== false) $notifLink = 'rentals.php';
                                                elseif (strpos($titleLower, 'inspection') !== false || strpos($msgLower, 'inspection') !== false) $notifLink = 'inspections.php';
                                                elseif (strpos($titleLower, 'payment') !== false || strpos($msgLower, 'payment') !== false) $notifLink = 'payments.php';
                                                elseif (strpos($titleLower, 'compliance') !== false || strpos($msgLower, 'permit') !== false) $notifLink = 'compliance.php';
                                            }
                                            $isUnread = (!isset($notification['is_read']) || $notification['is_read'] === false || $notification['is_read'] === '0' || $notification['is_read'] === 0);
                                        ?>
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background:<?php echo $isUnread ? '#fef2f2' : '#fff'; ?>;">
                                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                                <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                                <?php if ($isUnread): ?><span class="unread-dot" style="width:8px; height:8px; background:var(--accent-blue); border-radius:50%; display:inline-block;"></span><?php endif; ?>
                                            </div>
                                            <span style="font-size:11px; color:#64748b; display:block; margin-top:2px;"><?php echo displayField($notification, ['message','description']); ?></span>
                                            <span style="font-size:9.5px; color:#94a3b8; display:block; margin-top:4px;"><?php echo !empty($notification['created_at']) ? date('M d, Y h:i A', strtotime($notification['created_at'])) : ''; ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">No notifications found</div>
                                <?php endif; ?>
                            </div>
                            <div style="padding:8px; text-align:center; border-top:1px solid var(--card-border); background:#f8fafc;">
                                <a href="notifications.php" style="font-size:11px; font-weight:700; color:var(--accent-blue); text-decoration:none;">View All Notifications</a>
                            </div>
                        </div>
                    </div>

                    <div class="profile-wrapper">
                        <div class="profile-btn" id="profileToggle">
                            <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
                            <div class="info">
                                <div class="name"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="role">OWNER</div>
                            </div>
                            <i class="fa-solid fa-chevron-down chevron" style="color:#64748b; font-size:10px;"></i>
                        </div>

                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                            <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                            <div style="height:1px; background:var(--blue-soft); margin:4px 0;"></div>
                            <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
        </div>

        <main class="content">

            <?php if (!empty($uploadSuccess)): ?>
                <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="border-radius:12px; border:1px solid #86efac; background:#f0fdf4; color:#166534;">
                    <i class="fa-solid fa-circle-check fa-lg me-2"></i>
                    <div><?php echo h($uploadSuccess); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($uploadError)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="border-radius:12px; border:1px solid #fca5a5; background:#fef2f2; color:#991b1b;">
                    <i class="fa-solid fa-circle-exclamation fa-lg me-2"></i>
                    <div><?php echo h($uploadError); ?></div>
                </div>
            <?php endif; ?>

            <!-- HERO -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-cash-register me-1"></i> Payments &amp; Where to Pay</h2>
                    <p>Settle your LGU rental dues securely via E-Wallet, Bank Transfer, or Over-the-Counter and upload your proof of payment.</p>
                </div>
                <button type="button" class="btn-hero" data-bs-toggle="modal" data-bs-target="#uploadProofModal">
                    <i class="fa-solid fa-upload"></i> Upload Payment Proof
                </button>
            </div>

            <!-- HOW IT WORKS -->
            <div class="steps-strip">
                <div class="step"><span class="step-no">1</span><div><strong>Pay</strong><small>Use any official channel below.</small></div></div>
                <div class="step"><span class="step-no">2</span><div><strong>Upload proof</strong><small>Attach your receipt or screenshot.</small></div></div>
                <div class="step"><span class="step-no">3</span><div><strong>Get verified</strong><small>Treasury verifies it and you are notified here.</small></div></div>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card history" onclick="document.getElementById('transactions_section').scrollIntoView({behavior:'smooth'});" title="Click to view payment history">
                    <div>
                        <span class="stat-label">Payment History</span>
                        <div class="stat-number"><?php echo number_format($totalPayments); ?></div>
                        <span style="font-size:11px; color:#701a75; font-weight:700;"><?php echo number_format($verifiedCount); ?> verified &middot; <?php echo number_format($pendingCount); ?> pending &middot; <?php echo number_format($rejectedCount); ?> rejected</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                </div>

                <div class="stat-card collection" onclick="window.location.href='reports.php?tab=revenue'" title="Click to track revenue reports">
                    <div>
                        <span class="stat-label">Total Verified Payments</span>
                        <div class="stat-number"><?php echo formatCurrency($totalAmount); ?></div>
                        <span style="font-size:11px; color:#991b1b; font-weight:700;">This month: <?php echo formatCurrency($thisMonthAmt); ?></span>
                        <?php if ($momPct !== null): ?>
                            <span class="mom-chip <?php echo $momPct >= 0 ? 'up' : 'down'; ?>">
                                <i class="fa-solid fa-arrow-<?php echo $momPct >= 0 ? 'trend-up' : 'trend-down'; ?>"></i>
                                <?php echo ($momPct >= 0 ? '+' : '') . number_format($momPct, 1); ?>%
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                </div>

                <div class="stat-card balance" onclick="window.location.href='<?php echo h($buildUrl(['status' => 'pending', 'page' => 1])); ?>#transactions_section'" title="Payments you submitted that are waiting for LGU verification">
                    <div>
                        <span class="stat-label">Pending Verification</span>
                        <div class="stat-number"><?php echo formatCurrency($pendingAmount); ?></div>
                        <span style="font-size:11px; color:#713f12; font-weight:700;"><?php echo number_format($pendingCount); ?> awaiting Treasury &rarr;</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                </div>
            </div>

            <!-- CHANNELS -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-building-columns"></i> Where to Pay &amp; Available Payment Channels</span>
                    <span style="font-size:12px; color:#64748b; font-weight:600;">LGU Official Treasury Channels</span>
                </div>
                <div class="panel-body">
                    <p style="font-size:13.5px; color:#475569; margin-bottom:20px;">
                        Choose from the official channels below to pay your rental and business compliance dues. After paying, click the <strong>"Upload Payment Proof"</strong> button above to have it recorded in the system.
                    </p>

                    <div class="channels-grid">
                        <!-- E-Wallet -->
                        <div class="channel-card">
                            <div>
                                <div class="channel-header">
                                    <div class="channel-icon"><i class="fa-solid fa-wallet"></i></div>
                                    <div>
                                        <h4 class="channel-title">GCash / Maya (E-Wallet)</h4>
                                        <span class="channel-subtitle">Instant Transfer</span>
                                    </div>
                                </div>
                                <p class="channel-desc">Pay using your mobile wallet app. Scan the QR code or transfer to the official LGU Account.</p>

                                <div class="qr-code-container">
                                    <img src="<?php echo h($payCfg['gcash_qr']); ?>" alt="LGU Treasury QR Code" onerror="this.closest('.qr-code-container').classList.add('qr-missing')">
                                    <div class="qr-missing-note">QR code is not available right now. Please use the account number below.</div>
                                    <div class="qr-code-label"><i class="fa-solid fa-qrcode me-1"></i> Scan to Pay</div>
                                </div>

                                <div class="channel-details">
                                    <div><span>Account Name:</span> <strong><?php echo h($payCfg['gcash_name']); ?></strong></div>
                                    <div><span>GCash / Maya No:</span> <strong><?php echo h($payCfg['gcash_no']); ?></strong> <button type="button" class="copy-btn" data-copy="<?php echo h($payCfg['gcash_no']); ?>">Copy</button></div>
                                </div>
                            </div>
                            <button type="button" class="btn-upload" data-bs-toggle="modal" data-bs-target="#uploadProofModal" onclick="setPaymentMethod('gcash')">
                                <i class="fa-solid fa-upload"></i> Upload E-Wallet Receipt
                            </button>
                        </div>

                        <!-- Bank -->
                        <div class="channel-card">
                            <div>
                                <div class="channel-header">
                                    <div class="channel-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-building-columns"></i></div>
                                    <div>
                                        <h4 class="channel-title">Online Bank Transfer</h4>
                                        <span class="channel-subtitle">Landbank / BDO / BPI</span>
                                    </div>
                                </div>
                                <p class="channel-desc">Directly transfer funds from your online banking app to the official LGU Depository Account.</p>
                                <div class="channel-details">
                                    <div><span>Bank Name:</span> <strong><?php echo h($payCfg['bank_name']); ?></strong></div>
                                    <div><span>Account Name:</span> <strong><?php echo h($payCfg['bank_acct_name']); ?></strong></div>
                                    <div><span>Account No:</span> <strong><?php echo h($payCfg['bank_acct_no']); ?></strong> <button type="button" class="copy-btn" data-copy="<?php echo h($payCfg['bank_acct_no']); ?>">Copy</button></div>
                                </div>
                            </div>
                            <button type="button" class="btn-upload" data-bs-toggle="modal" data-bs-target="#uploadProofModal" onclick="setPaymentMethod('bank')">
                                <i class="fa-solid fa-upload"></i> Upload Bank Transfer Slip
                            </button>
                        </div>

                        <!-- Cash -->
                        <div class="channel-card">
                            <div>
                                <div class="channel-header">
                                    <div class="channel-icon" style="background:#d1fae5; color:#059669;"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                                    <div>
                                        <h4 class="channel-title">Over-the-Counter Cash</h4>
                                        <span class="channel-subtitle">Municipal Treasurer's Office</span>
                                    </div>
                                </div>
                                <p class="channel-desc">Pay in cash personally at the LGU Municipal Treasurer's Office cashiers from Monday to Friday (8 AM - 5 PM).</p>
                                <div class="channel-details">
                                    <div><span>Location:</span> <strong><?php echo h($payCfg['office_location']); ?></strong></div>
                                    <div><span>Window:</span> <strong><?php echo h($payCfg['office_window']); ?></strong></div>
                                </div>
                            </div>
                            <button type="button" class="btn-upload" data-bs-toggle="modal" data-bs-target="#uploadProofModal" onclick="setPaymentMethod('cash')">
                                <i class="fa-solid fa-upload"></i> Upload Official Receipt (OR)
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-row">
                <div class="panel">
                    <div class="panel-header"><span><i class="fa-solid fa-chart-column"></i> Verified Payments (Last 6 Months)</span></div>
                    <div class="panel-body">
                        <div class="chart-box">
                            <?php if (!$hasMonthlyData): ?>
                                <div class="chart-empty"><i class="fa-solid fa-chart-column"></i>No verified payments yet</div>
                            <?php endif; ?>
                            <canvas id="payMonthlyChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><span><i class="fa-solid fa-chart-pie"></i> By Payment Method</span></div>
                    <div class="panel-body">
                        <div class="chart-box">
                            <?php if (!$hasMethodData): ?>
                                <div class="chart-empty"><i class="fa-solid fa-chart-pie"></i>No verified payments yet</div>
                            <?php endif; ?>
                            <canvas id="payMethodChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TRANSACTIONS -->
            <div class="panel" id="transactions_section">
                <div class="panel-header">
                    <span><i class="fa-solid fa-clock-rotate-left"></i> Payment History &amp; Verification Status</span>
                    <a class="export-link" href="<?php echo h($buildUrl(['export' => 'csv'])); ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                </div>

                <div class="filter-bar">
                    <form method="GET" action="payments.php#transactions_section">
                        <div>
                            <label for="f_q">Search</label>
                            <input type="text" id="f_q" name="q" class="form-control" placeholder="Reference no., tracking no., business..." value="<?php echo h($fQuery); ?>" maxlength="60">
                        </div>
                        <div>
                            <label for="f_status">Status</label>
                            <select id="f_status" name="status" class="form-select">
                                <option value="all">All</option>
                                <option value="verified" <?php echo $fStatus === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                <option value="pending" <?php echo $fStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $fStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div>
                            <label for="f_method">Method</label>
                            <select id="f_method" name="method" class="form-select">
                                <option value="all">All</option>
                                <option value="gcash" <?php echo $fMethod === 'gcash' ? 'selected' : ''; ?>>E-Wallet</option>
                                <option value="bank" <?php echo $fMethod === 'bank' ? 'selected' : ''; ?>>Bank</option>
                                <option value="cash" <?php echo $fMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            </select>
                        </div>
                        <div>
                            <label for="f_month">Month</label>
                            <input type="month" id="f_month" name="month" class="form-control" value="<?php echo h($fMonth); ?>">
                        </div>
                        <div>
                            <label for="f_rental">Property</label>
                            <select id="f_rental" name="rental" class="form-select">
                                <option value="0">All</option>
                                <?php foreach ($userRentals as $fr): ?>
                                    <option value="<?php echo (int)$fr['id']; ?>" <?php echo $fRental === (int)$fr['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(!empty($fr['business_name']) ? $fr['business_name'] : $fr['rental_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter me-1"></i> Apply</button>
                            <?php if ($hasActiveFilters): ?><a class="btn-reset" href="payments.php#transactions_section">Reset</a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <?php if (!empty($payments)): ?>
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>OR / Ref Number</th>
                                <th>Rental Business</th>
                                <th>Purpose</th>
                                <th>Amount Paid</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <?php
                                $statusKey = strtolower((string)($payment['status'] ?? 'pending'));
                                if (in_array($statusKey, ['paid', 'verified', 'completed'], true)) $group = 'verified';
                                elseif (in_array($statusKey, ['rejected', 'cancelled'], true)) $group = 'rejected';
                                elseif ($statusKey === 'pending') $group = 'pending';
                                else $group = 'other';

                                $refVal = !empty($payment['or_number']) ? $payment['or_number'] : ($payment[$refColumnName] ?? 'N/A');
                                $bizLabel = !empty($payment['business_name']) ? $payment['business_name'] : ($payment['rental_name'] ?? '');
                                $purposeVal = (string)($payment['payment_purpose'] ?? '');
                                $proofPath = (string)($payment['proof_image'] ?? '');
                                $proofOk = ($proofPath !== '' && preg_match('#^uploads/payments/[A-Za-z0-9_.\-]+$#', $proofPath));
                                $rejectReason = ($group === 'rejected' && $rejectCol && !empty($payment[$rejectCol])) ? (string)$payment[$rejectCol] : '';
                                $submittedAt = !empty($payment['created_at']) ? date('M d, Y h:i A', strtotime($payment['created_at'])) : '';
                                $verifiedAt = ($hasVerifiedAt && !empty($payment['verified_at'])) ? date('M d, Y h:i A', strtotime($payment['verified_at'])) : '';
                                $payDateTxt = !empty($payment['payment_date']) ? date('M d, Y', strtotime($payment['payment_date'])) : 'N/A';
                            ?>
                            <tr>
                                <td><strong style="color:#0f172a; font-family:monospace;">#<?php echo h($refVal); ?></strong></td>
                                <td>
                                    <strong style="color:#0f172a;"><?php echo h($bizLabel); ?></strong><br>
                                    <span style="font-size:11px; color:#64748b;"><?php echo h($payment['tracking_no'] ?? ''); ?></span>
                                </td>
                                <td><span style="font-size:12px; color:#475569;"><?php echo $purposeVal !== '' ? h($purposeVal) : '&mdash;'; ?></span></td>
                                <td><strong style="color:var(--accent-blue);"><?php echo formatCurrency($payment['amount_paid'] ?? 0); ?></strong></td>
                                <td>
                                    <?php echo h($payDateTxt); ?>
                                    <?php if ($submittedAt !== ''): ?><span class="row-sub">Submitted <?php echo h($submittedAt); ?></span><?php endif; ?>
                                </td>
                                <td><?php echo getPaymentMethodBadge($payment['payment_method'] ?? ''); ?></td>
                                <td>
                                    <?php if ($group === 'verified'): ?>
                                        <span class="status-badge active"><i class="fa-solid fa-check me-1"></i> Verified</span>
                                        <?php if ($verifiedAt !== ''): ?><span class="row-sub"><?php echo h($verifiedAt); ?></span><?php endif; ?>
                                    <?php elseif ($group === 'pending'): ?>
                                        <span class="status-badge pending"><i class="fa-solid fa-clock me-1"></i> Pending Verification</span>
                                    <?php elseif ($group === 'rejected'): ?>
                                        <span class="status-badge rejected"><i class="fa-solid fa-ban me-1"></i> <?php echo h(ucfirst($statusKey)); ?></span>
                                        <span class="reject-note"><?php echo $rejectReason !== '' ? '<strong>Reason:</strong> ' . h($rejectReason) : 'Please resubmit a clear proof or contact the Treasurer\'s Office.'; ?></span>
                                    <?php else: ?>
                                        <span class="status-badge pending"><?php echo h($statusKey); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="act-btns">
                                        <?php if ($proofOk): ?>
                                            <button type="button" class="act-btn" onclick="viewProof(this)"
                                                    data-url="../<?php echo h($proofPath); ?>"
                                                    data-title="<?php echo h('#' . $refVal . ' - ' . $bizLabel); ?>">
                                                <i class="fa-solid fa-eye"></i> Proof
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($group === 'verified'): ?>
                                            <button type="button" class="act-btn" onclick="openReceipt(this)"
                                                    data-ref="<?php echo h($refVal); ?>"
                                                    data-business="<?php echo h($bizLabel); ?>"
                                                    data-tracking="<?php echo h($payment['tracking_no'] ?? ''); ?>"
                                                    data-purpose="<?php echo h($purposeVal !== '' ? $purposeVal : 'N/A'); ?>"
                                                    data-amount="<?php echo h(formatCurrency($payment['amount_paid'] ?? 0)); ?>"
                                                    data-date="<?php echo h($payDateTxt); ?>"
                                                    data-method="<?php echo h(methodLabel($payment['payment_method'] ?? '')); ?>"
                                                    data-verified="<?php echo h($verifiedAt !== '' ? $verifiedAt : 'Verified'); ?>">
                                                <i class="fa-solid fa-receipt"></i> Receipt
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($group === 'rejected'): ?>
                                            <button type="button" class="act-btn resubmit" onclick="openResubmit(this)"
                                                    data-rental-id="<?php echo (int)($payment['rental_id'] ?? 0); ?>"
                                                    data-purpose="<?php echo h($purposeVal); ?>"
                                                    data-method="<?php echo h(strtolower((string)($payment['payment_method'] ?? ''))); ?>"
                                                    data-amount="<?php echo h(number_format((float)($payment['amount_paid'] ?? 0), 2, '.', '')); ?>">
                                                <i class="fa-solid fa-rotate-right"></i> Resubmit
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="pager">
                        <span class="pager-info">Showing <?php echo number_format($showFrom); ?>&ndash;<?php echo number_format($showTo); ?> of <?php echo number_format($totalRows); ?> payment(s)</span>
                        <?php if ($totalPages > 1): ?>
                        <div class="pager-links">
                            <?php if ($page > 1): ?><a href="<?php echo h($buildUrl(['page' => $page - 1])); ?>#transactions_section">&laquo; Prev</a><?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a class="<?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo h($buildUrl(['page' => $i])); ?>#transactions_section"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?><a href="<?php echo h($buildUrl(['page' => $page + 1])); ?>#transactions_section">Next &raquo;</a><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-receipt"></i>
                        <?php if ($hasActiveFilters): ?>
                            <p>No payments match your filters. <a href="payments.php#transactions_section" style="color:var(--accent-blue); font-weight:700;">Reset filters</a></p>
                        <?php else: ?>
                            <p>No payment transactions found.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:var(--accent-blue); font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </main>
    </div>

    <!-- UPLOAD MODAL -->
    <div class="modal fade" id="uploadProofModal" tabindex="-1" aria-labelledby="uploadProofModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid var(--card-border);">
                <form action="payments.php" method="POST" enctype="multipart/form-data" id="proofForm">
                    <div class="modal-header" style="background: #f8fafc; border-bottom: 1px solid var(--card-border);">
                        <h5 class="modal-title fw-bold" id="uploadProofModalLabel" style="font-size: 16px; color: var(--accent-blue-dark);">
                            <i class="fa-solid fa-upload me-2"></i> Submit Payment Proof / Receipt
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="submit_payment_proof" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                        <div class="mb-3">
                            <label class="form-label">Select Rental Property <span class="text-danger">*</span></label>
                            <select name="rental_id" id="uploadRentalSelect" class="form-select" required onchange="filterRenewalOptions()">
                                <option value="">-- Choose your rental property --</option>
                                <?php foreach ($userRentals as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>">
                                        <?php echo h($r['tracking_no'] . ' - ' . (!empty($r['business_name']) ? $r['business_name'] : $r['rental_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Payment Purpose <span class="text-danger">*</span></label>
                            <select name="payment_purpose" id="payment_purpose_select" class="form-select" required onchange="toggleRenewalSelect(this.value)">
                                <option value="Permit Renewal" selected>Permit / Document Renewal Fee</option>
                                <option value="New Registration">New Property Registration Fee</option>
                                <option value="Inspection Fee">Field Inspection / Clearance Fee</option>
                                <option value="Penalty / Fine">Late Penalty / Fine</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <div class="mb-3" id="renewal_link_group">
                            <label class="form-label" style="color:var(--accent-blue);">
                                <i class="fa-solid fa-link me-1"></i> Link to Pending Renewal Request <span class="text-danger">*</span>
                            </label>
                            <select name="renewal_id" id="renewal_id_select" class="form-select" style="border-color: var(--accent-blue); background-color: var(--blue-soft);">
                                <option value="">-- Select Pending Renewal Application --</option>
                                <?php if (!empty($userPendingRenewals)): ?>
                                    <?php foreach ($userPendingRenewals as $ren): ?>
                                        <option value="<?php echo (int)$ren['id']; ?>" data-rental-id="<?php echo (int)$ren['rental_id']; ?>">
                                            <?php echo h($ren['document_type']); ?> (Tracking: <?php echo h($ren['tracking_no']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="0">No pending renewal requests found (General Payment)</option>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted d-block mt-1" style="font-size: 11px;">
                                LGU Staff will verify the renewal once this payment is linked to your application. <span id="renewalHint" style="color:#b45309; font-weight:700;"></span>
                            </small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount Paid (₱) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" max="1000000" name="amount_paid" id="amountInput" class="form-control" placeholder="0.00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                                <option value="">-- Select channel --</option>
                                <option value="gcash">E-Wallet (GCash / Maya)</option>
                                <option value="bank">Online Bank Transfer</option>
                                <option value="cash">Over-the-Counter Cash (Treasurer)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reference Number / OR Number <span class="text-danger">*</span></label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. 1029384756 or Ref No." required minlength="4" maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9\-\/ ]{3,59}" title="4-60 characters: letters, numbers, dash or slash">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Attach Receipt / Screenshot <span class="text-danger">*</span></label>
                            <input type="file" name="proof_file" id="proofFileInput" class="form-control" accept=".jpg, .jpeg, .png, .pdf" required>
                            <div id="proofFileInfo" style="font-size:11.5px; color:#166534; margin-top:4px; font-weight:600;"></div>
                            <div style="font-size:11px; color:#64748b; margin-top:4px;">Supported formats: JPG, PNG, PDF (Max size: 5MB)</div>
                        </div>
                    </div>
                    <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid var(--card-border);">
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="submitProofBtn" class="btn-upload" style="width:auto; padding:8px 20px;">Submit for Verification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PROOF VIEWER MODAL -->
    <div class="modal fade" id="proofModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;">
                <div class="modal-header" style="background:#f8fafc;">
                    <h5 class="modal-title fw-bold" id="proofModalTitle" style="font-size:14px;">Payment Proof</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="proofBody"></div>
            </div>
        </div>
    </div>

    <!-- RECEIPT MODAL -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;">
                <div class="modal-header" style="background:#f8fafc;">
                    <h5 class="modal-title fw-bold" style="font-size:15px;"><i class="fa-solid fa-receipt me-2"></i> Payment Receipt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="receiptPrintArea">
                    <div class="receipt-head">
                        <div class="receipt-brand">RENTRACK LGU</div>
                        <div class="receipt-sub">Payment Acknowledgement Receipt</div>
                    </div>
                    <table class="receipt-table">
                        <tr><td>Reference / OR No.</td><td id="rc_ref"></td></tr>
                        <tr><td>Business / Property</td><td id="rc_business"></td></tr>
                        <tr><td>Tracking No.</td><td id="rc_tracking"></td></tr>
                        <tr><td>Purpose</td><td id="rc_purpose"></td></tr>
                        <tr><td>Amount Paid</td><td id="rc_amount"></td></tr>
                        <tr><td>Payment Date</td><td id="rc_date"></td></tr>
                        <tr><td>Method</td><td id="rc_method"></td></tr>
                        <tr><td>Status</td><td>VERIFIED <span id="rc_verified" style="display:block; font-size:11px; color:#64748b; font-weight:600;"></span></td></tr>
                    </table>
                    <p class="receipt-note">System-generated acknowledgement of a verified payment for <?php echo h($userName); ?>, printed <?php echo h(date('M d, Y h:i A')); ?>. The official Treasury Official Receipt (OR) remains the authoritative document.</p>
                </div>
                <div class="modal-footer" style="background:#f8fafc;">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn-upload" style="width:auto; padding:8px 18px;" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print / Save as PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH MODAL -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="closeSearchModalOnOutside(event)">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <i class="fa-solid fa-magnifying-glass search-icon-main"></i>
                <input type="text" class="search-modal-input" id="modalSearchInput" placeholder="Search by tracking number, business name..." autocomplete="off">
                <div class="search-modal-close" onclick="closeSearchModal()"><i class="fa-solid fa-xmark"></i></div>
            </div>
            <div class="search-modal-body" id="modalSearchResults">
                <div style="text-align:center; padding:40px; color:#64748b; font-size:13px;">
                    <i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>
                    Type at least 2 characters to search records...
                </div>
            </div>
            <div class="search-shortcut-hint">
                <span>Quick search across all your property records</span>
                <span>Press <kbd>ESC</kbd> to close</span>
            </div>
        </div>
    </div>

    <!-- AI ASSISTANT -->
    <div id="aiAssistantWrapper" style="position:fixed; bottom:calc(25px + var(--safe-bottom)); right:calc(25px + var(--safe-right)); z-index:1060; font-family:'Plus Jakarta Sans', sans-serif;">
        <button id="aiToggleBtn" style="width:55px; height:55px; border-radius:50%; background:linear-gradient(135deg, #dc2626, #991b1b); border:none; color:#fff; font-size:22px; cursor:pointer; box-shadow:0 10px 25px rgba(220, 38, 38, 0.4); display:flex; align-items:center; justify-content:center;" title="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>
        <div id="aiChatWindow" style="position:absolute; bottom:70px; right:0; width:350px; height:480px; background:#ffffff; border:1px solid var(--card-border); border-radius:18px; box-shadow:0 20px 40px rgba(220, 38, 38, 0.2); display:none; flex-direction:column; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #dc2626, #991b1b); color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-robot" style="font-size:16px; color:#fecdd3;"></i>
                    <div>
                        <strong style="font-size:14px; display:block; font-family:'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size:10px; color:#fecdd3;">Owner LGU Assistant</span>
                    </div>
                </div>
                <button id="aiCloseBtn" style="background:transparent; border:none; color:#fff; font-size:16px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="aiChatBody" style="flex:1; padding:14px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:10px; font-size:12.5px;">
                <div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your payments, rentals, permits, or inspections.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How much have I paid?'..." style="flex:1; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    /* NAV MENU */
    const mainNavBar = document.getElementById('mainNavBar');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const drawerCloseBtn = document.getElementById('drawerCloseBtn');

    function openDrawer() {
        mainNavBar.classList.add('mobile-open');
        sidebarOverlay.classList.add('active');
        document.body.classList.add('drawer-open');
    }
    function closeDrawer() {
        mainNavBar.classList.remove('mobile-open');
        sidebarOverlay.classList.remove('active');
        document.body.classList.remove('drawer-open');
    }
    menuToggle?.addEventListener('click', openDrawer);
    sidebarOverlay?.addEventListener('click', closeDrawer);
    drawerCloseBtn?.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDrawer();
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => i.classList.remove('open'));
        }
    });

    document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            const parent = trigger.parentElement;
            const wasOpen = parent.classList.contains('open');
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => { if (i !== parent) i.classList.remove('open'); });
            parent.classList.toggle('open', !wasOpen);
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => i.classList.remove('open'));
        }
    });

    mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
        link.addEventListener('click', function() { if (window.innerWidth <= 1200) closeDrawer(); });
    });
    window.addEventListener('resize', function() { if (window.innerWidth > 1200) closeDrawer(); });

    /* CLOCK */
    function updateClock() {
        const t = document.getElementById('liveTime');
        if (!t) return;
        t.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    /* CSRF + POST helper (raw Response ang ibinabalik) */
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
    function postAction(action, body) {
        return fetch('?ajax_action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
            body: new URLSearchParams(body || {}).toString()
        });
    }

    /* NOTIFICATIONS */
    document.getElementById('notifClearBtn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        postAction('clear_notifs')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('notifBadgeCount')?.remove();
                    document.getElementById('notifHeaderCount')?.remove();
                    const lb = document.getElementById('notifListBody');
                    if (lb) lb.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:var(--accent-blue);"></i>You\'re all caught up!</div>';
                }
            });
    });

    function markNotificationReadAndRedirect(notifId, redirectUrl, element) {
        postAction('mark_notif_read', { id: notifId })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    element.querySelector('.unread-dot')?.remove();
                    element.style.background = '#fff';
                    const badge = document.getElementById('notifBadgeCount');
                    const headerCount = document.getElementById('notifHeaderCount');
                    if (badge) {
                        let c = parseInt(badge.textContent) - 1;
                        if (c > 0) { badge.textContent = c; if (headerCount) headerCount.textContent = c + ' New'; }
                        else { badge.remove(); headerCount?.remove(); }
                    }
                }
                window.location.href = redirectUrl;
            })
            .catch(() => { window.location.href = redirectUrl; });
    }

    /* DROPDOWNS */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    notifBtn?.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown?.classList.remove('show'); notifDropdown.classList.toggle('show'); });
    profileToggle?.addEventListener('click', (e) => { e.stopPropagation(); notifDropdown?.classList.remove('show'); profileDropdown.classList.toggle('show'); });
    if (notifDropdown) notifDropdown.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => { profileDropdown?.classList.remove('show'); notifDropdown?.classList.remove('show'); });

    /* SEARCH MODAL */
    const searchModalOverlay = document.getElementById('searchModalOverlay');
    const modalSearchInput = document.getElementById('modalSearchInput');
    const modalSearchResults = document.getElementById('modalSearchResults');

    function openSearchModal() { searchModalOverlay.classList.add('active'); setTimeout(() => modalSearchInput.focus(), 100); }
    function closeSearchModal() {
        searchModalOverlay.classList.remove('active');
        modalSearchInput.value = '';
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
    }
    function closeSearchModalOnOutside(event) { if (event.target === searchModalOverlay) closeSearchModal(); }

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openSearchModal(); }
        if (e.key === 'Escape') closeSearchModal();
    });

    let searchTimeout;
    modalSearchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        let query = this.value.trim();
        if (query.length < 2) {
            modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
            return;
        }
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:30px; color:#64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px; margin-bottom:8px; color:var(--accent-blue);"></i><div>Searching records...</div></div>`;
        searchTimeout = setTimeout(() => {
            fetch('?ajax_action=search&q=' + encodeURIComponent(query))
                .then(res => res.text())
                .then(html => { modalSearchResults.innerHTML = html; });
        }, 300);
    });

    /* AI ASSISTANT */
    const aiToggleBtn = document.getElementById('aiToggleBtn');
    const aiCloseBtn = document.getElementById('aiCloseBtn');
    const aiChatWindow = document.getElementById('aiChatWindow');
    const aiSendBtn = document.getElementById('aiSendBtn');
    const aiChatInput = document.getElementById('aiChatInput');
    const aiChatBody = document.getElementById('aiChatBody');

    aiToggleBtn?.addEventListener('click', () => {
        const isVisible = aiChatWindow.style.display === 'flex';
        aiChatWindow.style.display = isVisible ? 'none' : 'flex';
        if (!isVisible) aiChatInput.focus();
    });
    aiCloseBtn?.addEventListener('click', () => { aiChatWindow.style.display = 'none'; });

    function sendAiMessage() {
        const text = aiChatInput.value.trim();
        if (!text) return;
        aiChatBody.innerHTML += `<div style="background:var(--accent-blue); color:#fff; padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-end; border-bottom-right-radius:2px;">${escapeHtml(text)}</div>`;
        aiChatInput.value = '';
        aiChatBody.scrollTop = aiChatBody.scrollHeight;
        const loadingId = 'ai-loading-' + Date.now();
        aiChatBody.innerHTML += `<div id="${loadingId}" style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;"><i class="fa-solid fa-spinner fa-spin"></i> Thinking...</div>`;
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        fetch('?ajax_action=ai_chat&msg=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">${escapeHtml(String(data.reply || ''))}</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            })
            .catch(() => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background:var(--danger-bg); color:var(--danger); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">Sorry, an error occurred.</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            });
    }
    aiSendBtn?.addEventListener('click', sendAiMessage);
    aiChatInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAiMessage(); });

    function escapeHtml(text) {
        const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /* PAYMENT HELPERS */
    function setPaymentMethod(method) {
        const selectEl = document.getElementById('paymentMethodSelect');
        if (selectEl) selectEl.value = method;
    }

    function toggleRenewalSelect(purposeVal) {
        const renewalGroup = document.getElementById('renewal_link_group');
        const sel = document.getElementById('renewal_id_select');
        const show = purposeVal === 'Permit Renewal';
        if (renewalGroup) renewalGroup.style.display = show ? 'block' : 'none';
        if (sel && !show) sel.value = '';
        filterRenewalOptions();
    }

    /* Ipakita lang ang pending renewals ng napiling property; gawing required kung meron */
    function filterRenewalOptions() {
        const rental = document.getElementById('uploadRentalSelect')?.value || '';
        const sel = document.getElementById('renewal_id_select');
        const purpose = document.getElementById('payment_purpose_select')?.value || '';
        const hint = document.getElementById('renewalHint');
        if (!sel) return;
        let visible = 0;
        Array.from(sel.options).forEach(o => {
            const r = o.getAttribute('data-rental-id');
            if (r === null) return;
            const show = rental !== '' && r === rental;
            o.hidden = !show; o.disabled = !show;
            if (show) visible++;
        });
        if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled) sel.value = '';
        sel.required = (purpose === 'Permit Renewal' && visible > 0);
        if (hint) hint.textContent = (purpose === 'Permit Renewal' && rental !== '' && visible === 0) ? 'No pending renewal request for this property.' : '';
    }

    /* PROOF VIEWER */
    function viewProof(btn) {
        const url = btn.getAttribute('data-url') || '';
        document.getElementById('proofModalTitle').textContent = btn.getAttribute('data-title') || 'Payment Proof';
        const body = document.getElementById('proofBody');
        body.innerHTML = '';
        const isPdf = /\.pdf$/i.test(url);
        document.querySelector('#proofModal .modal-dialog')?.classList.toggle('is-pdf', isPdf);   // mas malapad lang para sa PDF
        if (isPdf) {
            const f = document.createElement('iframe');
            f.className = 'proof-frame'; f.src = url; body.appendChild(f);
        } else {
            const i = document.createElement('img');
            i.className = 'proof-img'; i.alt = 'Payment proof'; i.src = url; body.appendChild(i);
        }
        const open = document.createElement('a');
        open.className = 'proof-open'; open.href = url; open.target = '_blank'; open.rel = 'noopener';
        open.innerHTML = '<i class="fa-solid fa-up-right-from-square me-1"></i> Open full size';
        body.appendChild(open);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('proofModal')).show();
    }

    /* RECEIPT */
    function openReceipt(btn) {
        const map = { rc_ref: 'data-ref', rc_business: 'data-business', rc_tracking: 'data-tracking', rc_purpose: 'data-purpose',
                      rc_amount: 'data-amount', rc_date: 'data-date', rc_method: 'data-method', rc_verified: 'data-verified' };
        Object.keys(map).forEach(id => { document.getElementById(id).textContent = btn.getAttribute(map[id]) || ''; });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('receiptModal')).show();
    }

    /* RESUBMIT: i-prefill ang upload form mula sa na-reject na payment */
    function openResubmit(btn) {
        const rental = document.getElementById('uploadRentalSelect');
        const purpose = document.getElementById('payment_purpose_select');
        const method = document.getElementById('paymentMethodSelect');
        const amount = document.getElementById('amountInput');
        if (rental) rental.value = btn.getAttribute('data-rental-id') || '';
        const p = btn.getAttribute('data-purpose');
        if (purpose && p) purpose.value = p;
        const m = btn.getAttribute('data-method');
        if (method && m) method.value = m;
        if (amount) amount.value = btn.getAttribute('data-amount') || '';
        toggleRenewalSelect(purpose ? purpose.value : '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadProofModal')).show();
    }

    /* COPY ACCOUNT NUMBERS */
    document.querySelectorAll('.copy-btn').forEach(b => {
        b.addEventListener('click', () => {
            const val = b.getAttribute('data-copy') || '';
            const done = () => { const old = b.textContent; b.textContent = 'Copied!'; setTimeout(() => { b.textContent = old; }, 1500); };
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(val).then(done).catch(() => {});
            else { const t = document.createElement('textarea'); t.value = val; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
        });
    });

    /* UPLOAD FORM: client-side checks (naka-validate pa rin sa server) */
    const proofFileInput = document.getElementById('proofFileInput');
    const proofFileInfo = document.getElementById('proofFileInfo');
    proofFileInput?.addEventListener('change', function () {
        const f = this.files && this.files[0];
        if (!f) { proofFileInfo.textContent = ''; return; }
        if (f.size > 5 * 1024 * 1024) {
            this.value = '';
            proofFileInfo.style.color = '#b91c1c';
            proofFileInfo.textContent = 'File is larger than 5MB. Please choose a smaller file.';
            return;
        }
        proofFileInfo.style.color = '#166534';
        proofFileInfo.textContent = f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
    });
    document.getElementById('proofForm')?.addEventListener('submit', function (e) {
        const amt = parseFloat(document.getElementById('amountInput').value);
        if (!(amt > 0)) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'Invalid amount', text: 'Please enter an amount greater than zero.', confirmButtonColor: '#dc2626' }); return; }
        const btn = document.getElementById('submitProofBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Submitting...'; }   // iwas double submit
    });
    document.getElementById('uploadProofModal')?.addEventListener('shown.bs.modal', filterRenewalOptions);

    /* CHARTS */
    const peso = (n) => '\u20B1' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    if (window.Chart) {
        const mc = document.getElementById('payMonthlyChart');
        if (mc) new Chart(mc, {
            type: 'bar',
            data: { labels: <?php echo json_encode($chartMonthLabels); ?>, datasets: [{ label: 'Verified payments', data: <?php echo json_encode($chartMonthValues); ?>, backgroundColor: 'rgba(220, 38, 38, 0.85)', hoverBackgroundColor: '#991b1b', borderRadius: 8, maxBarThickness: 42 }] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + peso(c.parsed.y) } } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: (v) => peso(v) } } } }
        });
        const pc = document.getElementById('payMethodChart');
        if (pc) new Chart(pc, {
            type: 'doughnut',
            data: { labels: <?php echo json_encode($chartMethodLabels); ?>, datasets: [{ data: <?php echo json_encode($chartMethodValues); ?>, backgroundColor: ['#4338ca', '#dc2626', '#059669', '#94a3b8'], borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + peso(c.parsed) } } } }
        });
    }

    /* LOGOUT */
    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff', color: '#0f172a'
        }).then((res) => { if (res.isConfirmed) window.location.href = '../logout.php'; });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    </script>
</body>
</html>