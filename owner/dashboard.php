<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/* Fallback kung wala pang safe_error() sa config/auth */
if (!function_exists('safe_error')) {
    function safe_error($e) {
        error_log('[dashboard] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
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

/* Helper Function for PostgreSQL Table Check (cached per request) */
function tableExists($conn, $tableName) {
    static $cache = [];
    $key = strtolower($tableName);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => $key]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return $cache[$key] = false; }
}

/* Helper Function for PostgreSQL Column Check (cached per request) */
function columnExists($conn, $tableName, $columnName) {
    static $cache = [];
    $key = strtolower($tableName) . '.' . strtolower($columnName);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return $cache[$key] = false; }
}

/* MARK NOTIFICATION AS READ AJAX HANDLER (POST + CSRF) */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'mark_notif_read') {
    requirePostCsrf();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $notifId = (int) ($_POST['id'] ?? 0);

    if ($userId > 0 && $notifId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtUpdate = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :nid AND user_id = :uid");
            $stmtUpdate->execute([':nid' => $notifId, ':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => safe_error($e)]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* MARK ALL NOTIFICATIONS AS READ (POST + CSRF) */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'mark_all_read') {
    requirePostCsrf();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtAll = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid");
            $stmtAll->execute([':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => safe_error($e)]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* CLEAR / DELETE NOTIFICATIONS AJAX HANDLER (POST + CSRF) */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'clear_notifs') {
    requirePostCsrf();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtDelete = $conn->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmtDelete->execute([':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => safe_error($e)]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* AI ASSISTANT ENHANCED AJAX HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'ai_chat') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $question = trim(strtolower($_GET['msg'] ?? ''));

    $totalRentals = 0;
    $activeRentals = 0;
    $totalPaid = 0;
    $withPermit = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();

        if (columnExists($conn, 'rentals', 'status')) {
            if (columnExists($conn, 'rentals', 'compliance_status')) {
                $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND (LOWER(status::text) = 'active' OR (LOWER(status::text) = 'approved' AND LOWER(compliance_status::text) = 'compliant')) AND is_deleted = '0'");
            } else {
                $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
            }
            $stmtActive->execute([':uid' => $userId]);
            $activeRentals = (int)$stmtActive->fetchColumn();
        }

        if (tableExists($conn, 'documents')) {
            $stmtPermit = $conn->prepare("
                SELECT COUNT(DISTINCT d.rental_id) 
                FROM documents d 
                INNER JOIN rentals r ON d.rental_id = r.id 
                WHERE r.user_id = :uid 
                  AND r.is_deleted = '0' 
                  AND d.business_permit_no IS NOT NULL 
                  AND d.business_permit_no != ''
                  AND (LOWER(d.title) LIKE '%business permit%' OR LOWER(d.title) LIKE '%mayor%' OR LOWER(d.type) LIKE '%business%')
            ");
            $stmtPermit->execute([':uid' => $userId]);
            $withPermit = (int)$stmtPermit->fetchColumn();
        } elseif (columnExists($conn, 'rentals', 'business_permit_no')) {
            $stmtPermit = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND business_permit_no IS NOT NULL AND business_permit_no != '' AND is_deleted = '0'");
            $stmtPermit->execute([':uid' => $userId]);
            $withPermit = (int)$stmtPermit->fetchColumn();
        }
    }

    if (tableExists($conn, 'payments') && tableExists($conn, 'rentals')) {
        $stmtPayments = $conn->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE r.user_id = :uid AND r.is_deleted = '0'");
        $stmtPayments->execute([':uid' => $userId]);
        $totalPaid = (float)$stmtPayments->fetchColumn();
    }

    $reply = "Hello! I am Rentrack AI. You can ask me about your rentals, payments, permits, or inspections.";
    $link = null;

    /* Word-boundary matching para hindi mag-false match (hal. "hi" sa "this") */
    $has = function (array $words) use ($question) {
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/u', $question)) return true;
        }
        return false;
    };

    if ($has(['next inspection', 'susunod', 'upcoming inspection', 'inspection', 'schedule'])) {
        $nextText = 'You can check your upcoming visits in the Inspection Schedule page.';
        if (tableExists($conn, 'inspections') && columnExists($conn, 'inspections', 'inspection_date') && tableExists($conn, 'rentals')) {
            $stmtNext = $conn->prepare("SELECT MIN(i.inspection_date) FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE i.inspection_date >= CURRENT_DATE AND r.user_id = :uid AND r.is_deleted = '0'");
            $stmtNext->execute([':uid' => $userId]);
            $nextDate = $stmtNext->fetchColumn();
            $nextText = $nextDate
                ? 'Your next inspection is on ' . date('F j, Y', strtotime($nextDate)) . '.'
                : 'You have no upcoming inspections scheduled.';
        }
        $reply = $nextText;
        $link = 'inspection_schedule.php';
    } elseif ($has(['payment', 'paid', 'magkano', 'pera', 'bayad', 'due'])) {
        $reply = "Your total payments received across all properties is ₱" . number_format($totalPaid, 2) . ".";
        $link = 'payments.php';
    } elseif ($has(['permit', 'business', 'expire', 'expired', 'expiring'])) {
        $reply = "You have {$withPermit} rentals equipped with a valid business permit. Check the Expiring Documents panel on your dashboard for permits nearing expiry.";
        $link = 'compliance.php';
    } elseif ($has(['how many', 'total', 'rental', 'property', 'ilan'])) {
        $reply = "You currently have a total of {$totalRentals} registered rentals, with {$activeRentals} active properties under your account.";
        $link = 'rentals.php';
    } elseif ($has(['hello', 'hi', 'hey', 'kumusta', 'good morning', 'good afternoon'])) {
        $reply = "Hello! How can I help you manage your properties and compliance today?";
    } else {
        $reply = "As your LGU Assistant, I found that you have {$totalRentals} rentals and ₱" . number_format($totalPaid, 2) . " total payments. Try asking about your rentals, payments, permits, or next inspection.";
        $link = 'rentals.php';
    }

    echo json_encode(['reply' => $reply, 'link' => $link]);
    exit;
}

/* AJAX SEARCH HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2 && tableExists($conn, 'rentals')) {
        $searchTerm = '%' . addcslashes($query, '%_\\') . '%';
        $hasBarangay = columnExists($conn, 'rentals', 'barangay');
        $sql = "
            SELECT id, tracking_no, business_name, status, rental_name 
            FROM rentals 
            WHERE user_id = :uid 
              AND is_deleted = '0' 
              AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3"
              . ($hasBarangay ? " OR barangay ILIKE :q4" : "") . ")
            LIMIT 5
        ";
        $params = [
            ':uid' => $userId,
            ':q1' => $searchTerm,
            ':q2' => $searchTerm,
            ':q3' => $searchTerm
        ];
        if ($hasBarangay) $params[':q4'] = $searchTerm;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(220,38,38,0.1)';
                $badgeColor = '#dc2626';
                if (strtolower($status) === 'pending') { $badgeBg = 'rgba(217,119,6,0.15)'; $badgeColor = '#d97706'; }
                elseif (strtolower($status) === 'approved' || strtolower($status) === 'active') { $badgeBg = 'rgba(5,150,105,0.15)'; $badgeColor = '#059669'; }

                echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; color: #0f172a; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#fee2e2\';" onmouseout="this.style.background=\'#f8fafc\';">';
                echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                echo '<div style="font-weight: 700; font-size: 14px; color: #dc2626;">' . htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']) . ' <span style="color: #94a3b8; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name'] ?: $row['rental_name']) . '</div>';
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

/* CURRENT OWNER */
$userName = $_SESSION['fullname'] ?? 'Owner';
$userRole = $_SESSION['role'] ?? 'Owner';
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$userInitial = strtoupper(substr(trim($userName), 0, 1));

/* DEFAULT METRICS */
$totalRentals = 0;
$pendingRentals = 0;
$approvedRentals = 0;
$activeRentals = 0;
$completedRentals = 0;
$totalInspections = 0;
$todayInspections = 0;
$upcomingInspections = 0;
$totalPayments = 0;
$paymentsThisMonth = 0;
$withPermitCount = 0;
$unreadNotifCount = 0;

/* RENTALS DATA (OPTIMIZED SINGLE QUERY) */
if (tableExists($conn, 'rentals')) {
    $sqlRentals = "
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(status::text) = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN LOWER(status::text) = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN LOWER(status::text) = 'completed' THEN 1 ELSE 0 END) AS completed
    ";
    
    if (columnExists($conn, 'rentals', 'compliance_status')) {
        $sqlRentals .= ",
            SUM(CASE WHEN (LOWER(status::text) = 'active' OR (LOWER(status::text) = 'approved' AND LOWER(compliance_status::text) = 'compliant')) THEN 1 ELSE 0 END) AS active
        ";
    } else {
        $sqlRentals .= ",
            SUM(CASE WHEN LOWER(status::text) = 'active' THEN 1 ELSE 0 END) AS active
        ";
    }

    $sqlRentals .= " FROM rentals WHERE is_deleted='0' AND user_id = :uid";
    
    $stmt = $conn->prepare($sqlRentals);
    $stmt->execute([':uid' => $userId]);
    $rentalStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rentalStats) {
        $totalRentals = (int)$rentalStats['total'];
        $pendingRentals = (int)$rentalStats['pending'];
        $approvedRentals = (int)$rentalStats['approved'];
        $activeRentals = (int)$rentalStats['active'];
        $completedRentals = (int)$rentalStats['completed'];
    }

    if (tableExists($conn, 'documents')) {
        $stmtBP = $conn->prepare("
            SELECT COUNT(DISTINCT d.rental_id) 
            FROM documents d 
            INNER JOIN rentals r ON d.rental_id = r.id 
            WHERE r.user_id = :uid 
              AND r.is_deleted = '0' 
              AND d.business_permit_no IS NOT NULL 
              AND d.business_permit_no != ''
              AND (LOWER(d.title) LIKE '%business permit%' OR LOWER(d.title) LIKE '%mayor%' OR LOWER(d.type) LIKE '%business%')
        ");
        $stmtBP->execute([':uid' => $userId]);
        $withPermitCount = (int) $stmtBP->fetchColumn();
    } elseif (columnExists($conn, 'rentals', 'business_permit_no')) {
        $stmtBP = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE business_permit_no IS NOT NULL AND business_permit_no != '' AND is_deleted='0' AND user_id = :uid");
        $stmtBP->execute([':uid' => $userId]);
        $withPermitCount = (int) $stmtBP->fetchColumn();
    }
}

/* INSPECTIONS DATA */
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE r.user_id = :uid AND r.is_deleted='0'");
    $stmt->execute([':uid' => $userId]);
    $totalInspections = (int) $stmt->fetchColumn();

    if (columnExists($conn, 'inspections', 'inspection_date')) {
        $stmtToday = $conn->prepare("SELECT COUNT(*) FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE i.inspection_date = CURRENT_DATE AND r.user_id = :uid AND r.is_deleted='0'");
        $stmtToday->execute([':uid' => $userId]);
        $todayInspections = (int) $stmtToday->fetchColumn();

        $stmtUp = $conn->prepare("SELECT COUNT(*) FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE i.inspection_date > CURRENT_DATE AND r.user_id = :uid AND r.is_deleted='0'");
        $stmtUp->execute([':uid' => $userId]);
        $upcomingInspections = (int) $stmtUp->fetchColumn();
    }
}

/* PAYMENTS DATA */
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals')) {
    $stmtP = $conn->prepare("SELECT COALESCE(SUM(p.amount_paid),0) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE r.user_id = :uid AND r.is_deleted='0'");
    $stmtP->execute([':uid' => $userId]);
    $totalPayments = (float) $stmtP->fetchColumn();

    if (columnExists($conn, 'payments', 'payment_date') && columnExists($conn, 'payments', 'amount_paid')) {
        $stmtMonth = $conn->prepare("
            SELECT COALESCE(SUM(p.amount_paid),0) 
            FROM payments p 
            INNER JOIN rentals r ON p.rental_id = r.id 
            WHERE EXTRACT(MONTH FROM p.payment_date) = EXTRACT(MONTH FROM CURRENT_DATE) 
              AND EXTRACT(YEAR FROM p.payment_date) = EXTRACT(YEAR FROM CURRENT_DATE) 
              AND r.user_id = :uid AND r.is_deleted='0'
        ");
        $stmtMonth->execute([':uid' => $userId]);
        $paymentsThisMonth = (float) $stmtMonth->fetchColumn();
    }
}

/* NOTIFICATIONS DATA */
$notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        $stmtN = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (LOWER(is_read::text) IN ('0', 'false')) AND user_id = :uid");
        $stmtN->execute([':uid' => $userId]);
        $unreadNotifCount = (int) $stmtN->fetchColumn();
    }
    $stmtList = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $stmtList->execute([':uid' => $userId]);
    $notifications = $stmtList->fetchAll(PDO::FETCH_ASSOC);
}

/* RECENT RENTALS */
$recentRentals = [];
if (tableExists($conn, 'rentals')) {
    $stmtRec = $conn->prepare("SELECT * FROM rentals WHERE is_deleted='0' AND user_id = :uid ORDER BY created_at DESC LIMIT 8");
    $stmtRec->execute([':uid' => $userId]);
    $recentRentals = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
}

/* INSPECTION SCHEDULE */
$inspectionSchedule = [];
if (tableExists($conn, 'inspections') && columnExists($conn, 'inspections', 'inspection_date') && tableExists($conn, 'rentals')) {
    $stmtSched = $conn->prepare("SELECT i.* FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE i.inspection_date >= CURRENT_DATE AND r.user_id = :uid AND r.is_deleted='0' ORDER BY i.inspection_date ASC LIMIT 6");
    $stmtSched->execute([':uid' => $userId]);
    $inspectionSchedule = $stmtSched->fetchAll(PDO::FETCH_ASSOC);
}

/* ==============================================
   NEW: STATUS DISTRIBUTION (DOUGHNUT)
============================================== */
$statusLabels = [];
$statusValues = [];
$statusColorMap = [
    'pending' => '#f59e0b', 'approved' => '#2563eb', 'active' => '#10b981',
    'completed' => '#059669', 'rejected' => '#dc2626', 'cancelled' => '#94a3b8', 'draft' => '#cbd5e1'
];
$statusColors = [];
if (tableExists($conn, 'rentals') && columnExists($conn, 'rentals', 'status')) {
    $stmtSt = $conn->prepare("SELECT LOWER(status::text) AS st, COUNT(*) AS c FROM rentals WHERE is_deleted='0' AND user_id = :uid GROUP BY LOWER(status::text) ORDER BY c DESC");
    $stmtSt->execute([':uid' => $userId]);
    foreach ($stmtSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['st'] ?: 'draft';
        $statusLabels[] = ucfirst($key);
        $statusValues[] = (int)$r['c'];
        $statusColors[] = $statusColorMap[$key] ?? '#64748b';
    }
}
$hasStatusData = array_sum($statusValues) > 0;

/* ==============================================
   NEW: MONTHLY PAYMENTS (LAST 6 MONTHS) + MoM %
============================================== */
$payLabels = [];
$payValues = [];
$hasPayData = false;
$paymentsLastMonth = 0;
$paymentsMoM = null;
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals')
    && columnExists($conn, 'payments', 'payment_date') && columnExists($conn, 'payments', 'amount_paid')) {
    $stmtPM = $conn->prepare("
        SELECT TO_CHAR(p.payment_date, 'YYYY-MM') AS mk, COALESCE(SUM(p.amount_paid),0) AS total
        FROM payments p INNER JOIN rentals r ON p.rental_id = r.id
        WHERE r.user_id = :uid AND r.is_deleted='0'
          AND p.payment_date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
        GROUP BY TO_CHAR(p.payment_date, 'YYYY-MM')
    ");
    $stmtPM->execute([':uid' => $userId]);
    $payMap = [];
    foreach ($stmtPM->fetchAll(PDO::FETCH_ASSOC) as $r) $payMap[$r['mk']] = (float)$r['total'];

    for ($i = 5; $i >= 0; $i--) {
        $firstOfMonth = strtotime(date('Y-m-01') . " -$i months");
        $mk = date('Y-m', $firstOfMonth);
        $payLabels[] = date('M', $firstOfMonth);
        $v = $payMap[$mk] ?? 0;
        $payValues[] = $v;
        if ($v > 0) $hasPayData = true;
    }
    $paymentsLastMonth = $payValues[4] ?? 0;
    if ($paymentsLastMonth > 0) {
        $paymentsMoM = (($paymentsThisMonth - $paymentsLastMonth) / $paymentsLastMonth) * 100;
    }
}

/* ==============================================
   NEW: TOP PROPERTIES BY PAYMENTS
============================================== */
$topPropLabels = [];
$topPropValues = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && columnExists($conn, 'payments', 'amount_paid')) {
    $stmtTop = $conn->prepare("
        SELECT COALESCE(NULLIF(r.rental_name,''), NULLIF(r.business_name,''), 'Rental #' || r.id) AS label,
               COALESCE(SUM(p.amount_paid),0) AS total
        FROM payments p INNER JOIN rentals r ON p.rental_id = r.id
        WHERE r.user_id = :uid AND r.is_deleted='0'
        GROUP BY r.id, r.rental_name, r.business_name
        HAVING COALESCE(SUM(p.amount_paid),0) > 0
        ORDER BY total DESC LIMIT 5
    ");
    $stmtTop->execute([':uid' => $userId]);
    foreach ($stmtTop->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $topPropLabels[] = mb_strimwidth($r['label'], 0, 24, '…');
        $topPropValues[] = (float)$r['total'];
    }
}

/* ==============================================
   NEW: EXPIRING DOCUMENTS (<= 90 days or already expired)
   Gaya sa logic ng documents.php: tinitingnan ang permit_expiry_date at notes
============================================== */
$expiringDocs = [];
$expiredCount = 0;
$expiring30Count = 0;

if (tableExists($conn, 'documents') && tableExists($conn, 'rentals')) {
    try {
        // Kunin lahat ng permits ng user na may expiry
        $stmtExp = $conn->prepare("
            SELECT d.id, d.rental_id, d.title, d.type, d.business_permit_no, d.notes, d.permit_expiry_date,
                   COALESCE(NULLIF(r.rental_name,''), NULLIF(r.business_name,''), 'Rental #' || r.id) AS rental_label
            FROM documents d 
            INNER JOIN rentals r ON d.rental_id = r.id
            WHERE r.user_id = :uid AND r.is_deleted = '0'
              AND (LOWER(d.type) = 'permit' OR LOWER(d.title) LIKE '%permit%')
        ");
        $stmtExp->execute([':uid' => $userId]);
        $allPermits = $stmtExp->fetchAll(PDO::FETCH_ASSOC);
        
        $today = new DateTime(date('Y-m-d'));
        
        foreach ($allPermits as $p) {
            $validUntil = null;
            
            // Logic gaya sa documents.php
            if (!empty($p['permit_expiry_date'])) {
                $validUntil = $p['permit_expiry_date'];
            } elseif (!empty($p['notes']) && preg_match('/Valid until:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $p['notes'], $m)) {
                $validUntil = $m[1];
            }
            
            if ($validUntil) {
                $expiry = new DateTime(date('Y-m-d', strtotime($validUntil)));
                $interval = $today->diff($expiry);
                $days = (int)$interval->format('%r%a');
                
                // I-save kung expired, expiring within 30 days, o within 90 days
                if ($days <= 90) {
                    $expiringDocs[] = [
                        'id' => $p['id'],
                        'title' => $p['title'],
                        'rental_label' => $p['rental_label'],
                        'expiry_date' => $validUntil,
                        'days_left' => $days
                    ];
                    
                    if ($days < 0) {
                        $expiredCount++;
                    } elseif ($days <= 30) {
                        $expiring30Count++;
                    }
                }
            }
        }
        
        // I-sort by days left (pinakamalapit/expired muna)
        usort($expiringDocs, function($a, $b) {
            return $a['days_left'] <=> $b['days_left'];
        });
        
        // Limitahan sa 5 para sa dashboard
        $expiringDocs = array_slice($expiringDocs, 0, 5);
        
    } catch (PDOException $e) { 
        $expiringDocs = []; 
    }
}

/* ==============================================
   NEW: ACTION REQUIRED
============================================== */
$actionItems = [];
if ($pendingRentals > 0) {
    $actionItems[] = ['icon' => 'fa-clock', 'color' => '#d97706', 'bg' => '#fef3c7',
        'text' => $pendingRentals . ' rental request' . ($pendingRentals > 1 ? 's' : '') . ' awaiting review', 'link' => 'rental_requests.php'];
}
if ($expiredCount > 0) {
    $actionItems[] = ['icon' => 'fa-triangle-exclamation', 'color' => '#dc2626', 'bg' => '#fee2e2',
        'text' => $expiredCount . ' document' . ($expiredCount > 1 ? 's have' : ' has') . ' already expired', 'link' => 'renew_documents.php'];
}
if ($expiring30Count > 0) {
    $actionItems[] = ['icon' => 'fa-hourglass-half', 'color' => '#d97706', 'bg' => '#fef3c7',
        'text' => $expiring30Count . ' document' . ($expiring30Count > 1 ? 's' : '') . ' expiring within 30 days', 'link' => 'renew_documents.php'];
}
if (tableExists($conn, 'rentals') && columnExists($conn, 'rentals', 'compliance_status')) {
    $stmtNC = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE is_deleted='0' AND user_id = :uid AND LOWER(compliance_status::text) IN ('non-compliant','non_compliant','noncompliant')");
    $stmtNC->execute([':uid' => $userId]);
    $nonCompliant = (int)$stmtNC->fetchColumn();
    if ($nonCompliant > 0) {
        $actionItems[] = ['icon' => 'fa-file-shield', 'color' => '#dc2626', 'bg' => '#fee2e2',
            'text' => $nonCompliant . ' rental' . ($nonCompliant > 1 ? 's are' : ' is') . ' non-compliant', 'link' => 'compliance.php'];
    }
}
if (tableExists($conn, 'documents') && tableExists($conn, 'rentals') && columnExists($conn, 'documents', 'rental_id')) {
    $stmtMD = $conn->prepare("SELECT COUNT(*) FROM rentals r WHERE r.user_id = :uid AND r.is_deleted='0' AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.rental_id = r.id)");
    $stmtMD->execute([':uid' => $userId]);
    $missingDocs = (int)$stmtMD->fetchColumn();
    if ($missingDocs > 0) {
        $actionItems[] = ['icon' => 'fa-file-circle-exclamation', 'color' => '#7c3aed', 'bg' => '#ede9fe',
            'text' => $missingDocs . ' rental' . ($missingDocs > 1 ? 's have' : ' has') . ' no uploaded documents', 'link' => 'documents.php'];
    }
}
if ($todayInspections > 0) {
    $actionItems[] = ['icon' => 'fa-clipboard-check', 'color' => '#2563eb', 'bg' => '#dbeafe',
        'text' => $todayInspections . ' inspection' . ($todayInspections > 1 ? 's' : '') . ' scheduled today', 'link' => 'inspection_schedule.php'];
}

/* MONTHLY CHART DATA */
$monthlyLabels = [];
$monthlyValues = [];
$monthlyApproved = [];
$hasChartData = false;

if (tableExists($conn, 'rentals') && columnExists($conn, 'rentals', 'created_at')) {
    $stmtChart = $conn->prepare("
        SELECT 
            TO_CHAR(created_at, 'YYYY-MM') AS month_key,
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(status::text) = 'approved' THEN 1 ELSE 0 END) AS approved_count
        FROM rentals 
        WHERE is_deleted='0' 
          AND created_at IS NOT NULL 
          AND user_id = :uid 
          AND created_at >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
        GROUP BY TO_CHAR(created_at, 'YYYY-MM')
    ");
    $stmtChart->execute([':uid' => $userId]);
    
    $chartDataMap = [];
    while ($row = $stmtChart->fetch(PDO::FETCH_ASSOC)) {
        $chartDataMap[$row['month_key']] = [
            'total' => (int)$row['total'],
            'approved' => (int)$row['approved_count']
        ];
    }

    for ($i = 5; $i >= 0; $i--) {
        $monthKey = date('Y-m', strtotime("-$i months"));
        $monthlyLabels[] = date('M', strtotime("-$i months"));
        $val = $chartDataMap[$monthKey]['total'] ?? 0;
        $appVal = $chartDataMap[$monthKey]['approved'] ?? 0;
        
        $monthlyValues[] = $val;
        $monthlyApproved[] = $appVal;

        if ($val > 0 || $appVal > 0) {
            $hasChartData = true;
        }
    }
} 

if (empty($monthlyLabels)) {
    $monthlyLabels = ['Jan','Feb','Mar','Apr','May','Jun'];
    $monthlyValues = [0,0,0,0,0,0];
    $monthlyApproved = [0,0,0,0,0,0];
}

function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}

function statusClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'approved': return 'approved';
        case 'active': return 'active';
        case 'completed': return 'completed';
        case 'rejected': case 'cancelled': return 'rejected';
        default: return 'pending';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Owner Dashboard | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-light: #f8fafc;
            --bg-card: #ffffff;
            --card-border: #e2e8f0;

            --accent-blue: #dc2626;
            --accent-blue-dark: #991b1b;
            --accent-blue-light: #f87171;
            --blue-soft: #fee2e2;

            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --text-faint: #94a3b8;

            --ok: #059669; --ok-bg: #d1fae5;
            --warn: #d97706; --warn-bg: #fef3c7;
            --danger: #dc2626; --danger-bg: #fee2e2;
            --info: #2563eb; --info-bg: #dbeafe;

            --r-sm: 8px; --r-md: 10px; --r-lg: 12px; --r-xl: 16px; --r-xxl: 18px;

            --sh-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
            --sh-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.03);
            --sh-md: 0 4px 10px -2px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04);
            --sh-lg: 0 12px 24px -8px rgba(15, 23, 42, 0.10), 0 4px 8px -4px rgba(15, 23, 42, 0.05);

            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes pulseBadge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

        html, body {
            margin: 0 !important; padding: 0 !important; border: 0 !important;
            height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-light); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
        }

        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.4) transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; border-radius: 20px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 20px; }

        /* FIX: Tinanggal ang touch-action: none para gumana ang clicks sa iOS */
        body.drawer-open { overflow: hidden !important; }
        body.drawer-open #aiAssistantWrapper { display: none !important; }

        .page-container {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            width: 100%;
            margin: 0 !important; padding: 0 !important;
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* ==============================================
           1. TOP OFFICIAL BAR
        ============================================== */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            margin: 0 !important;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px;
            color: #fecdd3; font-size: 11px; font-weight: 600;
        }
        .official-header-badge {
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
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
                gap: 3px 8px; padding: 6px 12px; text-align: left; align-items: center;
            }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }
        @media (max-width: 380px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .oh-title { font-size: 8.5px; gap: 4px; }
            .official-header-badge { font-size: 8px; padding: 2px 6px; }
            .oh-sub { font-size: 8px; }
        }

        /* ==============================================
           2. MAIN WRAPPER
        ============================================== */
        .main-wrapper {
            flex: 1; display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            margin: 0 !important; padding: 0 !important;
            background: var(--bg-light);
        }

        /* FIX: Itinaas ang z-index para manaig sa overlay at makapasok ang drawer sa tamang stacking context */
        .sticky-header { position: sticky; top: 0; z-index: 1000; background: #ffffff; }

        /* ==============================================
           3. TOP NAVBAR
        ============================================== */
        .top-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 18px; background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            margin: 0 !important; gap: 14px;
            flex-wrap: nowrap; min-height: 62px;
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
            color: var(--accent-blue); cursor: pointer;
            font-size: 16px; flex-shrink: 0;
        }
        .menu-toggle:active { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }

        /* ==============================================
           4. HORIZONTAL NAV BAR WITH DROPDOWNS
        ============================================== */
        .main-nav-bar {
            display: flex; align-items: center;
            background: transparent; border: none; padding: 0;
            position: relative; flex-shrink: 0; z-index: 45;
        }

        .nav-menu {
            list-style: none; margin: 0; padding: 0;
            display: flex; align-items: center; gap: 2px; flex-wrap: nowrap;
        }

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
            color: #334155; text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.15s ease;
        }
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue); font-size: 14px; text-align: center; flex-shrink: 0; }
        .nav-dropdown a .menu-badge {
            margin-left: auto; background: #fbbf24; color: #7c2d12;
            font-size: 10px; font-weight: 700;
            padding: 1px 8px; border-radius: 999px;
        }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px;
            padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i {
            position: absolute; left: 13px; top: 50%;
            transform: translateY(-50%);
            color: var(--accent-blue); z-index: 2; font-size: 13px;
        }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #0f172a;
            font-size: 13px; outline: none; cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: var(--text-faint); }
        .search-container:hover input { border-color: var(--accent-blue); background: var(--blue-soft); }

        /* RIGHT CONTROLS */
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
        .nav-icon:active { background: var(--blue-soft); }
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
        .profile-btn:hover { background: var(--blue-soft); }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent-blue);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px; overflow: hidden;
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            width: 240px; background: #ffffff; border: 1px solid var(--card-border);
            border-radius: 14px; padding: 8px;
            box-shadow: 0 16px 36px rgba(220, 38, 38, 0.15);
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease; z-index: 60;
        }
        .notification-dropdown { width: 360px; padding: 0; }
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
        .notification-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; }
        .notification-item strong { display: block; font-size: 13px; margin-bottom: 2px; color: var(--accent-blue); }
        .notification-item p { font-size: 12px; color: var(--text-secondary); margin: 0; }
        .notification-item small { display: block; font-size: 10px; color: var(--text-faint); margin-top: 4px; }

        /* ==============================================
           CONTENT
        ============================================== */
        .content {
            flex: 1; padding: 22px 24px 40px;
            padding-bottom: calc(40px + var(--safe-bottom));
            overflow-y: auto; background: var(--bg-light);
            -webkit-overflow-scrolling: touch;
        }

        .dashboard-shell { display: flex; flex-direction: column; gap: 18px; }

        .welcome-banner {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            border-radius: 18px;
            padding: 22px 26px;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.2);
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::before {
            content: '';
            position: absolute;
            right: -20px; bottom: -30px;
            width: 180px; height: 180px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }
        .welcome-content h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px; font-weight: 800;
            margin: 0 0 4px 0;
            letter-spacing: -0.3px;
        }
        .welcome-content p {
            font-size: 13px; color: #fee2e2; margin: 0; font-weight: 500;
        }
        .welcome-clock-box {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px; border-radius: 12px;
            text-align: right;
            backdrop-filter: blur(6px);
        }
        .welcome-clock-time {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16px; font-weight: 800;
            color: #ffffff; line-height: 1.1;
        }
        .welcome-clock-date {
            font-size: 11px; color: #fecdd3;
            font-weight: 600; text-transform: uppercase;
        }

        @media (max-width: 768px) {
            .welcome-banner { flex-direction: column; align-items: flex-start; gap: 12px; }
            .welcome-clock-box { text-align: left; width: 100%; }
        }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }

        .summary-card {
            border-radius: 18px; padding: 20px;
            display: flex; justify-content: space-between; align-items: center;
            color: #ffffff; transition: var(--transition);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); border: none;
            text-decoration: none; cursor: pointer;
        }
        .summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0, 0, 0, 0.15); color: #ffffff; }

        .summary-card span { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.9; }
        .summary-card strong { font-size: 32px; font-weight: 800; font-family: 'Space Grotesk', sans-serif; display: block; line-height: 1; margin-top: 6px; }

        .summary-icon {
            width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px;
            background: rgba(255, 255, 255, 0.22); color: #ffffff; backdrop-filter: blur(4px);
        }

        .card-blue { background: linear-gradient(135deg, #e11d48, #9f1239); }
        .card-purple { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .card-teal { background: linear-gradient(135deg, #059669, #047857); }
        .card-orange { background: linear-gradient(135deg, #ea580c, #c2410c); }

        .quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-top: 4px; }

        .action-card {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
            padding: 14px 10px; border-radius: 14px; text-decoration: none; color: #ffffff;
            transition: var(--transition); min-height: 85px; border: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
        }
        .action-card:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(0, 0, 0, 0.18); color: #ffffff; }

        .action-card .icon-box {
            width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 16px; background: rgba(255, 255, 255, 0.25); color: #ffffff;
        }
        .action-card span { font-size: 11.5px; font-weight: 700; text-align: center; white-space: nowrap; }

        .qa-blue { background: linear-gradient(135deg, #dc2626, #991b1b); }
        .qa-purple { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .qa-teal { background: linear-gradient(135deg, #059669, #047857); }
        .qa-emerald { background: linear-gradient(135deg, #10b981, #047857); }
        .qa-coral { background: linear-gradient(135deg, #e11d48, #9f1239); }
        .qa-indigo { background: linear-gradient(135deg, #0891b2, #155e75); }

        .dashboard-card-panel {
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 18px; padding: 20px; transition: var(--transition);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.05);
        }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .panel-heading h3 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; margin: 0; color: #0f172a; }
        .panel-heading a {
            font-size: 11.5px; font-weight: 700; color: var(--accent-blue); text-decoration: none; padding: 4px 12px;
            border-radius: 999px; background: var(--blue-soft); transition: var(--transition);
        }
        .panel-heading a:hover { background: var(--accent-blue); color: #fff; }

        .overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .content-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px; }

        .dashboard-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .dashboard-table th { padding: 8px 10px; text-align: left; color: #64748b; font-size: 10.5px; text-transform: uppercase; border-bottom: 1px solid var(--card-border); }
        .dashboard-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }

        .status-badge { padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 700; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.approved { background: #dbeafe; color: #2563eb; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.completed { background: #d1fae5; color: #059669; }
        .status-badge.rejected { background: #fee2e2; color: #dc2626; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* NEW PANELS */
        .charts-row { display: grid; grid-template-columns: 1fr 1.5fr; gap: 14px; }
        .triple-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        @media (max-width: 1100px) { .triple-row { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 992px) { .charts-row { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .triple-row { grid-template-columns: 1fr; } }

        .chart-empty {
            position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.88); z-index: 10; border-radius: 12px; text-align: center; padding: 10px;
        }
        .chart-empty i { font-size: 28px; color: var(--accent-blue-light); margin-bottom: 8px; }
        .chart-empty span { font-size: 12.5px; font-weight: 600; color: var(--text-muted); }
        .chart-empty small { font-size: 11px; color: var(--text-faint); }

        .action-item {
            display: flex; align-items: center; gap: 12px; padding: 10px 12px;
            border: 1px solid var(--card-border); border-radius: 12px; text-decoration: none;
            color: var(--text-secondary); font-size: 12.5px; font-weight: 600; transition: var(--transition);
        }
        .action-item:hover { transform: translateX(3px); border-color: var(--accent-blue-light); color: var(--text-primary); }
        .action-item .ai-ico { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; }
        .action-item .ai-arrow { margin-left: auto; color: var(--text-faint); font-size: 11px; }

        .doc-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border: 1px solid var(--card-border); border-radius: 12px; background: #f8fafc; }
        .doc-row .doc-title { font-size: 12px; font-weight: 700; color: #0f172a; display: block; }
        .doc-row .doc-sub { font-size: 10.5px; color: #64748b; }
        .expiry-pill { font-size: 10px; font-weight: 800; padding: 3px 9px; border-radius: 999px; white-space: nowrap; text-transform: uppercase; }
        .expiry-pill.red { background: #fee2e2; color: #dc2626; }
        .expiry-pill.yellow { background: #fef3c7; color: #d97706; }
        .expiry-pill.green { background: #d1fae5; color: #059669; }

        .mom-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
        .mom-chip.up { background: #d1fae5; color: #059669; }
        .mom-chip.down { background: #fee2e2; color: #dc2626; }

        .ai-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 10px 0; background: #fff; border-top: 1px solid var(--card-border); }
        .ai-chip {
            border: 1px solid var(--card-border); background: #f8fafc; color: var(--accent-blue-dark);
            font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; cursor: pointer; font-family: inherit;
        }
        .ai-chip:hover { background: var(--blue-soft); border-color: var(--accent-blue-light); }

        /* ==============================================
           SEARCH MODAL
        ============================================== */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(153, 27, 27, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding-top: 10vh;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }

        .search-modal-content {
            width: 90%; max-width: 650px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 20px;
            box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2), 0 0 30px rgba(220, 38, 38, 0.1);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }

        .search-modal-header {
            display: flex; align-items: center; padding: 16px 20px;
            border-bottom: 1px solid var(--card-border); background: #f8fafc; position: relative;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue); margin-right: 14px; }
        .search-modal-input {
            flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600;
        }
        .search-modal-input::placeholder { color: var(--text-faint); }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .search-modal-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }

        .search-modal-body { padding: 20px; max-height: 380px; overflow-y: auto; }
        .search-modal-body::-webkit-scrollbar { width: 6px; }
        .search-modal-body::-webkit-scrollbar-thumb { background: var(--accent-blue-light); border-radius: 999px; }

        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 20px;
            background: #f8fafc; border-top: 1px solid var(--card-border); font-size: 11px; color: var(--text-muted);
        }
        .search-shortcut-hint kbd {
            background: var(--blue-soft); border: 1px solid var(--card-border); padding: 2px 6px; border-radius: 4px; color: var(--accent-blue-dark); font-family: inherit; font-weight: 700;
        }

        /* ==============================================
           RESPONSIVE
        ============================================== */
        @media (max-width: 1400px) {
            .nav-link { padding: 10px 8px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 11px; }
        }

        @media (max-width: 1200px) {
            .top-navbar {
                padding: 10px 14px;
                padding-top: calc(10px + var(--safe-top));
                gap: 10px; flex-wrap: wrap; min-height: 60px;
            }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; }
            .menu-toggle { display: flex; }

            .search-wrapper {
                order: 5; width: 100%;
                padding: 0; margin-top: 4px; margin-bottom: 4px;
                max-width: 100%; flex-basis: 100%;
            }
            .search-container { max-width: 100%; }

            /* Drawer position fixed - Z-index 2000 para manaig sa lahat ng elements sa loob ng sticky header */
            .main-nav-bar {
                position: fixed; top: 0; left: -100%;
                width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh;
                flex-direction: column;
                align-items: stretch; justify-content: flex-start;
                padding: 0; z-index: 2000;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            }
            .main-nav-bar.mobile-open { left: 0 !important; }

            .mobile-drawer-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 18px;
                padding-top: calc(16px + var(--safe-top));
                background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
                color: #fff; flex-shrink: 0;
                border-bottom: 2px solid #7f1d1d;
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
                background: rgba(255,255,255,0.18);
                border: 1px solid rgba(255,255,255,0.35);
                color: #fff; cursor: pointer; font-size: 15px;
                display: flex; align-items: center; justify-content: center;
            }
            .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

            .main-nav-bar .nav-menu {
                flex-direction: column; width: 100%;
                justify-content: flex-start; gap: 4px;
                padding: 14px;
                padding-bottom: calc(20px + var(--safe-bottom));
            }
            .nav-item { width: 100%; }
            .nav-link {
                padding: 14px 16px; border-radius: 10px;
                justify-content: flex-start;
                font-size: 14.5px; min-height: 48px;
            }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            /* FIX: Inayos ang selector at idinagdag ang transform: none at width: 100% */
            .nav-dropdown,
            .nav-item .nav-dropdown,
            .nav-item.align-right .nav-dropdown {
                position: static; left: auto; right: auto;
                transform: none !important; opacity: 1; visibility: visible;
                box-shadow: none; border: none;
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc; margin-top: 4px;
                border-radius: 10px; min-width: 0;
                width: 100%;
            }
            .nav-item.open .nav-dropdown,
            .nav-item.align-right.open .nav-dropdown { 
                max-height: 600px !important; 
                padding: 8px 0 8px 16px !important; 
                transform: none !important;
                width: 100%;
                left: 0;
            }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999;
                backdrop-filter: blur(3px);
                -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
                pointer-events: none;
            }
            .sidebar-overlay.active { 
                display: block; 
                opacity: 1; 
                pointer-events: auto;
            }

            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
            .profile-dropdown { width: 220px; }
        }

        @media (max-width: 992px) {
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .welcome-banner { padding: 18px 20px; }
            .welcome-content h2 { font-size: 18px; }
            .welcome-content p { font-size: 12px; }
            .summary-card strong { font-size: 26px; }
            .dashboard-card-panel { padding: 16px; }
            .panel-heading h3 { font-size: 15px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
            
            .search-modal-overlay { padding-top: 5vh; }
            .search-modal-content { width: 95%; }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .welcome-content h2 { font-size: 16px; }
            .summary-card strong { font-size: 22px; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; }
            .action-card { min-height: 78px; }
            .action-card span { font-size: 10.5px; }
            .dashboard-footer { font-size: 10px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input,
            .search-modal-input { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <!-- 1. TOP OFFICIAL BAR -->
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
        <!-- 2. STICKY HEADER -->
        <div class="sticky-header">
            <header class="top-navbar">
                <div class="navbar-left">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
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

                <!-- HORIZONTAL NAV MENU WITH DROPDOWNS -->
                <nav class="main-nav-bar" id="mainNavBar">
                    <div class="mobile-drawer-header">
                        <div class="drawer-brand">
                            <div class="drawer-brand-logo">RT</div>
                            <div class="drawer-brand-text">
                                <h2>RENTRACK</h2>
                                <span>Owner Portal</span>
                            </div>
                        </div>
                        <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close menu">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link active">
                                <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                            </a>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-building nav-ico"></i> Rentals
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="rentals.php"><i class="fa-solid fa-building"></i> Rental Registrations</a>
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
                            <button type="button" class="nav-link" data-dropdown>
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
                        <input type="text" placeholder="Search tracking no, rental name, barangay..." readonly style="cursor: pointer;">
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
                                    <?php if ($unreadNotifCount > 0): ?>
                                        <button type="button" id="notifReadAllBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Mark all read</button>
                                    <?php endif; ?>
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
                                </div>
                            </div>
                            <div style="max-height:280px; overflow-y:auto;" id="notifListBody">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <?php
                                            $notifLink = 'rentals.php';
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
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background: <?php echo $isUnread ? '#fef2f2' : '#fff'; ?>;">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                                <?php if ($isUnread): ?><span class="unread-dot" style="width:8px; height:8px; background:#dc2626; border-radius:50%; display:inline-block;"></span><?php endif; ?>
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
                            <i class="fa-solid fa-chevron-down chevron" style="color: #64748b; font-size: 10px;"></i>
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

            <!-- FIX: OVERLAY AY INILIPAT SA LOOB NG STICKY-HEADER PARA MAGKASAMA SILA NG DRAWER SA STACKING CONTEXT -->
            <div class="sidebar-overlay" id="sidebarOverlay"></div>
        </div>

        <main class="content">
            <div class="dashboard-shell">

                <div class="welcome-banner">
                    <div class="welcome-content">
                        <h2 id="greetingTitle">Welcome, <?php echo htmlspecialchars($userName); ?>!</h2>
                        <p>Monitor your properties, review rental requests, and check compliance updates here.</p>
                    </div>
                    <div class="welcome-clock-box">
                        <div class="welcome-clock-time" id="bannerLiveTime"><?php echo date('h:i:s A'); ?></div>
                        <div class="welcome-clock-date"><?php echo date('M d, Y'); ?></div>
                    </div>
                </div>

                <div class="summary-grid">
                    <a href="rentals.php" class="summary-card card-blue">
                        <div>
                            <span>Total Rentals</span>
                            <strong><?php echo number_format($totalRentals); ?></strong>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-building"></i></div>
                    </a>

                    <a href="rental_requests.php" class="summary-card card-purple">
                        <div>
                            <span>Pending Requests</span>
                            <strong><?php echo number_format($pendingRentals); ?></strong>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-clock"></i></div>
                    </a>

                    <a href="rentals.php?status=Active" class="summary-card card-teal">
                        <div>
                            <span>Active Rentals</span>
                            <strong><?php echo number_format($activeRentals); ?></strong>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-key"></i></div>
                    </a>

                    <a href="inspection_schedule.php" class="summary-card card-orange">
                        <div>
                            <span>Today's Inspections</span>
                            <strong><?php echo number_format($todayInspections); ?></strong>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    </a>
                </div>

                <div class="dashboard-card-panel">
                    <div class="panel-heading" style="margin-bottom:10px;">
                        <h3>Quick Actions</h3>
                    </div>
                    <div class="quick-actions-grid">
                        <a href="rental_requests.php" class="action-card qa-blue">
                            <div class="icon-box"><i class="fa-solid fa-file-circle-check"></i></div>
                            <span>Rental Requests</span>
                        </a>
                        <a href="schedule.php" class="action-card qa-purple">
                            <div class="icon-box"><i class="fa-solid fa-calendar-days"></i></div>
                            <span>Rental Schedule</span>
                        </a>
                        <a href="inspection_schedule.php" class="action-card qa-teal">
                            <div class="icon-box"><i class="fa-solid fa-calendar-check"></i></div>
                            <span>Inspection Schedule</span>
                        </a>
                        <a href="inspections.php" class="action-card qa-emerald">
                            <div class="icon-box"><i class="fa-solid fa-clipboard-check"></i></div>
                            <span>Inspections</span>
                        </a>
                        <a href="reports.php" class="action-card qa-indigo">
                            <div class="icon-box"><i class="fa-solid fa-chart-column"></i></div>
                            <span>Reports</span>
                        </a>
                    </div>
                </div>

                <div class="overview-grid">
                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Rental Status</h3>
                            <a href="rentals.php">View All</a>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;"><i class="fa-solid fa-circle" style="color:#f59e0b; font-size:8px;"></i> Pending</span>
                                <strong style="color:#0f172a;"><?php echo number_format($pendingRentals); ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;"><i class="fa-solid fa-circle" style="color:#2563eb; font-size:8px;"></i> Approved</span>
                                <strong style="color:#0f172a;"><?php echo number_format($approvedRentals); ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;"><i class="fa-solid fa-circle" style="color:#10b981; font-size:8px;"></i> Active</span>
                                <strong style="color:#0f172a;"><?php echo number_format($activeRentals); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>LGU Compliance</h3>
                            <a href="compliance.php">View All</a>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;"><i class="fa-solid fa-file-invoice" style="color:var(--accent-blue); margin-right:4px;"></i> With Business Permit</span>
                                <strong style="color:#0f172a;"><?php echo number_format($withPermitCount); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Inspections Overview</h3>
                            <a href="inspections.php">View All</a>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px;">
                                <span>Total Scheduled</span>
                                <strong style="font-size:16px; color:var(--accent-blue);"><?php echo number_format($totalInspections); ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px;">
                                <span>Upcoming Visits</span>
                                <strong style="font-size:16px; color:#059669;"><?php echo number_format($upcomingInspections); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Payments Summary</h3>
                            <a href="payments.php">View All</a>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="color:#64748b; font-size:13px;">Total Paid</span>
                            <strong style="font-size:18px; color:#059669;">₱<?php echo number_format($totalPayments, 2); ?></strong>
                        </div>
                        <div style="font-size:11.5px; color:#64748b; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span>Paid this month: <strong style="color:#0f172a;">₱<?php echo number_format($paymentsThisMonth, 2); ?></strong></span>
                            <?php if ($paymentsMoM !== null): ?>
                                <span class="mom-chip <?php echo $paymentsMoM >= 0 ? 'up' : 'down'; ?>">
                                    <i class="fa-solid fa-arrow-<?php echo $paymentsMoM >= 0 ? 'trend-up' : 'trend-down'; ?>"></i>
                                    <?php echo ($paymentsMoM >= 0 ? '+' : '') . number_format($paymentsMoM, 1); ?>% vs last month
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="content-grid">
                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Rental Registration Overview</h3>
                        </div>
                        <div style="height:210px; position: relative;">
                            <?php if (!$hasChartData): ?>
                                <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,0.85); z-index: 10; border-radius: 12px;">
                                    <i class="fa-solid fa-chart-line" style="font-size: 32px; color: var(--accent-blue-light); margin-bottom: 10px;"></i>
                                    <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">No rental data yet</span>
                                    <span style="font-size: 11px; color: var(--text-faint);">Register a property to see the trend.</span>
                                </div>
                            <?php endif; ?>
                            <canvas id="rentalChart"></canvas>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Inspection Schedule</h3>
                            <a href="inspection_schedule.php">View All</a>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php if (!empty($inspectionSchedule)): ?>
                                <?php foreach ($inspectionSchedule as $inspection): ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 10px; background:#f8fafc; border:1px solid var(--card-border); border-radius:10px;">
                                        <div>
                                            <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($inspection, ['property_name', 'business_name', 'name']); ?></strong>
                                            <span style="font-size:10.5px; color:#64748b;"><?php echo displayField($inspection, ['inspection_type', 'type']); ?></span>
                                        </div>
                                        <span style="font-size:10.5px; font-weight:700; color:var(--accent-blue); background:var(--blue-soft); padding:2px 6px; border-radius:6px;">
                                            <?php echo displayField($inspection, ['inspection_date']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:20px; color:#64748b; font-size:12px;">No upcoming inspections scheduled</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- NEW: ACTION REQUIRED + EXPIRING DOCUMENTS + TOP PROPERTIES -->
                <div class="triple-row">
                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3><i class="fa-solid fa-bolt" style="color:var(--accent-blue); margin-right:6px;"></i>Action Required</h3>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php if (!empty($actionItems)): ?>
                                <?php foreach ($actionItems as $item): ?>
                                    <a href="<?php echo htmlspecialchars($item['link']); ?>" class="action-item">
                                        <span class="ai-ico" style="background:<?php echo $item['bg']; ?>; color:<?php echo $item['color']; ?>;"><i class="fa-solid <?php echo $item['icon']; ?>"></i></span>
                                        <span><?php echo htmlspecialchars($item['text']); ?></span>
                                        <i class="fa-solid fa-chevron-right ai-arrow"></i>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:24px 10px; color:#64748b; font-size:12.5px;">
                                    <i class="fa-solid fa-circle-check" style="font-size:26px; color:#059669; display:block; margin-bottom:8px;"></i>
                                    All caught up! Walang kailangang gawin ngayon.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3><i class="fa-solid fa-hourglass-half" style="color:var(--accent-blue); margin-right:6px;"></i>Expiring Documents</h3>
                            <a href="renew_documents.php">Renew</a>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php if (!empty($expiringDocs)): ?>
                                <?php foreach ($expiringDocs as $doc): ?>
                                    <?php
                                        $dl = (int)$doc['days_left'];
                                        if ($dl < 0) { $pillClass = 'red'; $pillText = 'Expired ' . abs($dl) . 'd ago'; }
                                        elseif ($dl <= 15) { $pillClass = 'red'; $pillText = $dl . 'd left'; }
                                        elseif ($dl <= 45) { $pillClass = 'yellow'; $pillText = $dl . 'd left'; }
                                        else { $pillClass = 'green'; $pillText = $dl . 'd left'; }
                                    ?>
                                    <div class="doc-row">
                                        <div style="min-width:0;">
                                            <span class="doc-title"><?php echo htmlspecialchars($doc['title'] ?: 'Document'); ?></span>
                                            <span class="doc-sub"><?php echo htmlspecialchars($doc['rental_label']); ?> &middot; <?php echo date('M d, Y', strtotime($doc['expiry_date'])); ?></span>
                                        </div>
                                        <span class="expiry-pill <?php echo $pillClass; ?>"><?php echo htmlspecialchars($pillText); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:24px 10px; color:#64748b; font-size:12.5px;">
                                    <i class="fa-solid fa-file-circle-check" style="font-size:26px; color:#059669; display:block; margin-bottom:8px;"></i>
                                    No documents expiring in the next 90 days.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Top Properties by Payments</h3>
                            <a href="payments.php">View All</a>
                        </div>
                        <div style="height:220px; position:relative;">
                            <?php if (empty($topPropValues)): ?>
                                <div class="chart-empty"><i class="fa-solid fa-ranking-star"></i><span>No payment data yet</span><small>Payments per property will show here.</small></div>
                            <?php endif; ?>
                            <canvas id="topPropChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- NEW: STATUS DOUGHNUT + MONTHLY PAYMENTS -->
                <div class="charts-row">
                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Rental Status Distribution</h3>
                            <a href="rentals.php">View All</a>
                        </div>
                        <div style="height:240px; position:relative;">
                            <?php if (!$hasStatusData): ?>
                                <div class="chart-empty"><i class="fa-solid fa-chart-pie"></i><span>No rental data yet</span><small>Register a property to see the breakdown.</small></div>
                            <?php endif; ?>
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <div class="dashboard-card-panel">
                        <div class="panel-heading">
                            <h3>Monthly Payments (Last 6 Months)</h3>
                            <a href="payments.php">View All</a>
                        </div>
                        <div style="height:240px; position:relative;">
                            <?php if (!$hasPayData): ?>
                                <div class="chart-empty"><i class="fa-solid fa-peso-sign"></i><span>No payments recorded yet</span><small>Payments received will appear per month.</small></div>
                            <?php endif; ?>
                            <canvas id="paymentsChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card-panel">
                    <div class="panel-heading">
                        <h3>Recent Rental Registrations</h3>
                        <a href="rentals.php">View All</a>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Property</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentRentals)): ?>
                                    <?php foreach ($recentRentals as $rental): ?>
                                        <tr>
                                            <td>#<?php echo displayField($rental, ['rental_id', 'id']); ?></td>
                                            <td><?php echo displayField($rental, ['customer_name', 'client_name', 'business_name']); ?></td>
                                            <td><?php echo displayField($rental, ['rental_name', 'property_name']); ?></td>
                                            <td><?php echo displayField($rental, ['created_at', 'start_date']); ?></td>
                                            <td>
                                                <?php $st = $rental['status'] ?? 'Pending'; ?>
                                                <span class="status-badge <?php echo statusClass($st); ?>"><?php echo htmlspecialchars($st); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align:center; padding:16px; color:#64748b;">No rental records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:var(--accent-blue); font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </main>
    </div>

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
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>
                    Type at least 2 characters to search records...
                </div>
            </div>
            <div class="search-shortcut-hint">
                <span>Quick search across all your property records</span>
                <span>Press <kbd>ESC</kbd> to close</span>
            </div>
        </div>
    </div>

    <!-- FLOATING AI ASSISTANT WIDGET -->
    <div id="aiAssistantWrapper" style="position: fixed; bottom: calc(25px + var(--safe-bottom)); right: calc(25px + var(--safe-right)); z-index: 1050; font-family: 'Plus Jakarta Sans', sans-serif;">
        <button id="aiToggleBtn" style="width: 55px; height: 55px; border-radius: 50%; background: linear-gradient(135deg, #dc2626, #991b1b); border: none; color: #fff; font-size: 22px; cursor: pointer; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4); display: flex; align-items: center; justify-content: center; transition: transform 0.2s ease;" title="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>

        <div id="aiChatWindow" style="position: absolute; bottom: 70px; right: 0; width: 350px; height: 480px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 18px; box-shadow: 0 20px 40px rgba(220, 38, 38, 0.2); display: none; flex-direction: column; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #dc2626, #991b1b); color: #fff; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-robot" style="font-size: 16px; color: #fecdd3;"></i>
                    <div>
                        <strong style="font-size: 14px; display: block; font-family: 'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size: 10px; color: #fecdd3;">Owner LGU Assistant</span>
                    </div>
                </div>
                <button id="aiCloseBtn" style="background: transparent; border: none; color: #fff; font-size: 16px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div id="aiChatBody" style="flex: 1; padding: 14px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; font-size: 12.5px;">
                <div style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">
                    Hello <?php echo htmlspecialchars($userName); ?>! I am your AI Assistant. You can ask me about your rentals, payments, business permits, or inspections.
                </div>
            </div>

            <div class="ai-chips">
                <button type="button" class="ai-chip" data-q="How many rentals do I have?">My Rentals</button>
                <button type="button" class="ai-chip" data-q="How much have I paid?">Payments</button>
                <button type="button" class="ai-chip" data-q="When is my next inspection?">Next Inspection</button>
                <button type="button" class="ai-chip" data-q="Do I have business permits?">Permits</button>
            </div>
            <div style="padding: 10px; background: #ffffff; display: flex; gap: 8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How many rentals do I have?'..." style="flex: 1; padding: 8px 12px; border: 1px solid var(--card-border); border-radius: 999px; outline: none; font-size: 12.5px; background: #f8fafc;">
                <button id="aiSendBtn" style="background: var(--accent-blue); border: none; color: #fff; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-paper-plane" style="font-size: 12px;"></i></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    /* ============================================
       NAV MENU (HORIZONTAL + MOBILE DRAWER)
    ============================================ */
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
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const parent = trigger.parentElement;
            const wasOpen = parent.classList.contains('open');
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                if (item !== parent) item.classList.remove('open');
            });
            parent.classList.toggle('open', !wasOpen);
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1200) closeDrawer();
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 1200) closeDrawer();
    });

    /* ============================================
       CLOCK + GREETING
    ============================================ */
    function updateClockAndGreeting() {
        const timeBox = document.getElementById('liveTime');
        const bannerTimeBox = document.getElementById('bannerLiveTime');
        const greetingTitle = document.getElementById('greetingTitle');

        const now = new Date();
        const hours = now.getHours();
        const ownerName = <?php echo json_encode($userName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        let greetingText = "Welcome";
        if (hours >= 3 && hours < 12) greetingText = "Good Morning";
        else if (hours >= 12 && hours < 18) greetingText = "Good Afternoon";
        else greetingText = "Good Evening";

        if (greetingTitle) greetingTitle.textContent = `${greetingText}, ${ownerName}!`;

        const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (timeBox) timeBox.textContent = timeString;
        if (bannerTimeBox) bannerTimeBox.textContent = timeString;
    }
    setInterval(updateClockAndGreeting, 1000);
    updateClockAndGreeting();

    /* ============================================
       NOTIFICATIONS
    ============================================ */
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

    function postAction(action, body) {
        return fetch('?ajax_action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
            body: new URLSearchParams(body || {}).toString()
        }).then(res => res.json());
    }

    const notifReadAllBtn = document.getElementById('notifReadAllBtn');
    if (notifReadAllBtn) {
        notifReadAllBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            postAction('mark_all_read').then(data => {
                if (!data.success) return;
                document.getElementById('notifBadgeCount')?.remove();
                document.getElementById('notifHeaderCount')?.remove();
                notifReadAllBtn.remove();
                document.querySelectorAll('#notifListBody .notif-drop-item').forEach(el => {
                    el.style.background = '#fff';
                    el.querySelector('.unread-dot')?.remove();
                });
            }).catch(err => console.error(err));
        });
    }

    const notifClearBtn = document.getElementById('notifClearBtn');
    if (notifClearBtn) {
        notifClearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            postAction('clear_notifs')
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('notifBadgeCount');
                        const headerCount = document.getElementById('notifHeaderCount');
                        if (badge) badge.remove();
                        if (headerCount) headerCount.remove();
                        const listBody = document.getElementById('notifListBody');
                        if (listBody) {
                            listBody.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:var(--accent-blue);"></i>You\'re all caught up!</div>';
                        }
                    }
                })
                .catch(err => console.error(err));
        });
    }

    function markNotificationReadAndRedirect(notifId, redirectUrl, element) {
        postAction('mark_notif_read', { id: notifId })
            .then(data => {
                if (data.success) {
                    const dot = element.querySelector('.unread-dot');
                    if (dot) dot.remove();
                    element.style.background = '#fff';

                    const badge = document.getElementById('notifBadgeCount');
                    const headerCount = document.getElementById('notifHeaderCount');
                    if (badge) {
                        let currentCount = parseInt(badge.textContent) - 1;
                        if (currentCount > 0) {
                            badge.textContent = currentCount;
                            if (headerCount) headerCount.textContent = currentCount + ' New';
                        } else {
                            badge.remove();
                            if (headerCount) headerCount.remove();
                        }
                    }
                }
                window.location.href = redirectUrl;
            })
            .catch(() => { window.location.href = redirectUrl; });
    }

    /* ============================================
       PROFILE + NOTIFICATION DROPDOWNS
    ============================================ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    notifBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown?.classList.remove('show');
        notifDropdown.classList.toggle('show');
    });

    profileToggle?.addEventListener('click', (e) => {
        e.stopPropagation();
        notifDropdown?.classList.remove('show');
        profileDropdown.classList.toggle('show');
    });

       if (notifDropdown) notifDropdown.addEventListener('click', (e) => e.stopPropagation());

    document.addEventListener('click', () => {
        profileDropdown?.classList.remove('show');
        notifDropdown?.classList.remove('show');
    });

    /* ============================================
       SEARCH MODAL
    ============================================ */
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
                <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>
                Type at least 2 characters to search records...
            </div>
        `;
    }
    function closeSearchModalOnOutside(event) {
        if (event.target === searchModalOverlay) closeSearchModal();
    }

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openSearchModal();
        }
        if (e.key === 'Escape') closeSearchModal();
    });

    let searchTimeout;
    modalSearchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        let query = this.value.trim();
        if (query.length < 2) {
            modalSearchResults.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>
                    Type at least 2 characters to search records...
                </div>
            `;
            return;
        }
        modalSearchResults.innerHTML = `
            <div style="text-align: center; padding: 30px; color: #64748b;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; color: var(--accent-blue);"></i>
                <div>Searching records...</div>
            </div>
        `;
        searchTimeout = setTimeout(() => {
            fetch('?ajax_action=search&q=' + encodeURIComponent(query))
                .then(res => res.text())
                .then(html => { modalSearchResults.innerHTML = html; });
        }, 300);
    });

    /* ============================================
       AI ASSISTANT
    ============================================ */
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

        aiChatBody.innerHTML += `
            <div style="background: var(--accent-blue); color: #fff; padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-end; border-bottom-right-radius: 2px;">
                ${escapeHtml(text)}
            </div>
        `;
        aiChatInput.value = '';
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        const loadingId = 'ai-loading-' + Date.now();
        aiChatBody.innerHTML += `
            <div id="${loadingId}" style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">
                <i class="fa-solid fa-spinner fa-spin"></i> Thinking...
            </div>
        `;
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        fetch('?ajax_action=ai_chat&msg=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                document.getElementById(loadingId).remove();
                const safeLink = (data.link && /^[a-z0-9_\-]+\.php$/i.test(data.link)) ? data.link : null;
                aiChatBody.innerHTML += `
                    <div style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">
                        ${escapeHtml(String(data.reply || ''))}
                        ${safeLink ? `<a href="${safeLink}" style="display:block; margin-top:6px; font-weight:700; color:var(--accent-blue); text-decoration:none;">Open page <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>` : ''}
                    </div>
                `;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            })
            .catch(() => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `
                    <div style="background: var(--danger-bg); color: var(--danger); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">
                        Sorry, an error occurred while connecting to the AI. Please try again later.
                    </div>
                `;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            });
    }

    document.querySelectorAll('.ai-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            aiChatInput.value = chip.dataset.q || '';
            sendAiMessage();
        });
    });

    aiSendBtn?.addEventListener('click', sendAiMessage);
    aiChatInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAiMessage(); });

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /* ============================================
       LOGOUT
    ============================================ */
    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) window.location.href = '../logout.php';
        });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);

    /* ============================================
       CHART
    ============================================ */
    const ctx = document.getElementById('rentalChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthlyLabels); ?>,
                datasets: [{
                    label: 'Registered',
                    data: <?php echo json_encode($monthlyValues); ?>,
                    borderColor: '#dc2626',
                    backgroundColor: 'rgba(220, 38, 38, 0.12)',
                    fill: true, tension: 0.4
                }, {
                    label: 'Approved',
                    data: <?php echo json_encode($monthlyApproved); ?>,
                    borderColor: '#f87171',
                    borderDash: [5, 5], tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#334155' } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b', precision: 0 } }
                }
            }
        });
    }

    /* ============================================
       NEW CHARTS
    ============================================ */
    const peso = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

    /* Rental Status Doughnut */
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusValues); ?>,
                    backgroundColor: <?php echo json_encode($statusColors); ?>,
                    borderColor: '#ffffff', borderWidth: 3, hoverOffset: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { color: '#334155', usePointStyle: true, boxWidth: 8 } } }
            }
        });
    }

    /* Monthly Payments Bar */
    const payCtx = document.getElementById('paymentsChart');
    if (payCtx) {
        new Chart(payCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($payLabels); ?>,
                datasets: [{
                    label: 'Payments',
                    data: <?php echo json_encode($payValues); ?>,
                    backgroundColor: 'rgba(220, 38, 38, 0.8)',
                    hoverBackgroundColor: '#991b1b',
                    borderRadius: 8, maxBarThickness: 42
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ' ' + peso(c.parsed.y) } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b' } },
                    y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b', callback: (v) => peso(v) } }
                }
            }
        });
    }

    /* Top Properties Horizontal Bar */
    const topCtx = document.getElementById('topPropChart');
    if (topCtx) {
        new Chart(topCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($topPropLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($topPropValues); ?>,
                    backgroundColor: ['#dc2626', '#ea580c', '#f59e0b', '#7c3aed', '#0891b2'],
                    borderRadius: 6, maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ' ' + peso(c.parsed.x) } }
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b', callback: (v) => peso(v) } },
                    y: { grid: { display: false }, ticks: { color: '#334155' } }
                }
            }
        });
    }
    </script>
</body>
</html>