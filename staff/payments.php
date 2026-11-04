<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

/* Staff/admin lang ang pwedeng pumasok sa page na ito */
if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['role'] ?? '')), ['staff', 'admin', 'administrator'], true)) {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");
require_once "includes/auth.php";

/* =====================================================
   HELPER FUNCTIONS FOR PDO & POSTGRESQL
===================================================== */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

/* Fallback kung wala pang safe_error() sa auth/config */
if (!function_exists('safe_error')) {
    function safe_error($e) {
        error_log('[staff/payments] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

/* POST + CSRF guard para sa mga AJAX na nagbabago ng data */
function requirePostCsrf() {
    header('Content-Type: application/json; charset=utf-8');
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
}

function staffMethodGroup($m) {
    $m = strtolower(trim((string)$m));
    if (in_array($m, ['gcash', 'maya', 'online', 'e-wallet'], true)) return 'E-Wallet';
    if (in_array($m, ['bank', 'bank transfer', 'online bank transfer'], true)) return 'Bank Transfer';
    if (in_array($m, ['cash', 'over-the-counter (treasurer)'], true)) return 'Cash';
    if ($m === 'card') return 'Card';
    if ($m === 'check') return 'Check';
    return $m !== '' ? ucfirst($m) : 'Other';
}

// ==========================
// AJAX REQUEST HANDLERS
// ==========================
if (isset($_GET['ajax_action'])) {
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    if ($_GET['ajax_action'] === 'search') {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) >= 2) {
            $searchTerm = '%' . addcslashes($query, '%_\\') . '%';
            $sql = "
                SELECT r.id, r.tracking_no, r.business_name, r.status, u.fullname AS owner_name
                FROM rentals r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE (r.tracking_no ILIKE :q OR r.business_name ILIKE :q OR u.fullname ILIKE :q)
                  AND r.is_deleted = FALSE
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':q' => $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results && count($results) > 0) {
                echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
                foreach ($results as $row) {
                    $status = $row['status'] ?? 'Draft';
                    $badgeBg = '#f3f4f6'; $badgeColor = '#4b5563';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none; gap: 10px;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1;">';
                    echo '<div style="font-weight: 600; font-size: 13px; color: #dc2626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' . htmlspecialchars($row['tracking_no']) . ' • ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 11px; color: #4b5563;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase; flex-shrink: 0;">' . htmlspecialchars($status) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            } else {
                echo '<div style="text-align: center; padding: 30px; color: #6b7280; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>';
            }
        }
        exit;
    }

    if ($_GET['ajax_action'] === 'read_notif') {
        requirePostCsrf();
        if ($staffId > 0 && tableExists($conn, 'notifications')) {
            try {
                $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE (user_id = :staff_id OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
                $stmt->execute([':staff_id' => $staffId]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false]);
            }
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

// ==========================
// STAFF INFORMATION
// ==========================
$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

$hasRefColumn = columnExists($conn, 'payments', 'reference_number');
$hasPurposeColumn = columnExists($conn, 'payments', 'payment_purpose');
$hasFeeTypeColumn = columnExists($conn, 'payments', 'fee_type');
$hasRenewalIdColumn = columnExists($conn, 'payments', 'renewal_id');

$proofColumnName = null;
foreach (['proof_image', 'payment_proof', 'receipt_image', 'proof_path', 'attachment'] as $candidate) {
    if (columnExists($conn, 'payments', $candidate)) {
        $proofColumnName = $candidate;
        break;
    }
}

if (tableExists($conn, 'notifications')) {
    $markReadStmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND (notification_type::text = 'Payment' OR title ILIKE '%Payment%') AND is_read = FALSE");
    $markReadStmt->execute([':uid' => $staffId]);
}

// ==========================
// FILTERS, QUERY, PAGINATION
// ==========================
$getStr = function ($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : ''; };
$isYmd = function ($s) { $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s; };

$hasVoidCol     = columnExists($conn, 'payments', 'is_voided');
$hasCreatedAt   = columnExists($conn, 'payments', 'created_at');
$hasVerifiedAt  = columnExists($conn, 'payments', 'verified_at');
$hasVerifiedBy  = columnExists($conn, 'payments', 'verified_by');
$hasRejectReason = columnExists($conn, 'payments', 'reject_reason');
$hasRentalDeleted = columnExists($conn, 'rentals', 'is_deleted');
$voidSql = $hasVoidCol ? " AND (p.is_voided = FALSE OR p.is_voided IS NULL)" : "";
$tsCol   = $hasCreatedAt ? 'p.created_at' : 'p.id';

$refCols = [];
foreach (['or_number', 'reference_no', 'reference_number'] as $rc) {
    if (columnExists($conn, 'payments', $rc)) $refCols[] = $rc;
}
$refSql = function ($alias) use ($refCols) {
    if (empty($refCols)) return "''";
    $parts = [];
    foreach ($refCols as $c) $parts[] = "NULLIF($alias.$c::text, '')";
    return "COALESCE(" . implode(', ', $parts) . ", '')";
};

$SQL_V = "LOWER(p.status::text) IN ('paid', 'verified', 'completed')";
$SQL_P = "LOWER(p.status::text) = 'pending'";
$SQL_R = "LOWER(p.status::text) IN ('rejected', 'cancelled')";
$dateExpr = $hasVerifiedAt ? "COALESCE(p.verified_at::date, p.payment_date::date)" : "p.payment_date::date";
$joinBase = "FROM payments p INNER JOIN rentals r ON p.rental_id = r.id INNER JOIN users u ON r.user_id = u.id";
$baseWhere = "1=1" . ($hasRentalDeleted ? " AND r.is_deleted = FALSE" : "") . $voidSql;

/* Filters (tinatanggap pa rin ang lumang ?status=Cancelled/Paid na links) */
$statusMap = ['pending' => 'Pending', 'verified' => 'Verified', 'paid' => 'Verified', 'completed' => 'Verified', 'rejected' => 'Rejected', 'cancelled' => 'Rejected'];
$filterStatus = $statusMap[strtolower($getStr('status'))] ?? 'all';
$search   = mb_substr(trim($getStr('search')), 0, 80);
$fMethod  = in_array($getStr('method'), ['cash', 'ewallet', 'bank'], true) ? $getStr('method') : 'all';
$allowedPurposes = ['Permit Renewal', 'New Registration', 'Inspection Fee', 'Penalty / Fine', 'Others'];
$fPurpose = in_array($getStr('purpose'), $allowedPurposes, true) ? $getStr('purpose') : 'all';
$fFrom    = $isYmd($getStr('from')) ? $getStr('from') : '';
$fTo      = $isYmd($getStr('to')) ? $getStr('to') : '';
$fSort    = in_array($getStr('sort'), ['newest', 'oldest', 'amount_desc', 'amount_asc'], true) ? $getStr('sort') : 'queue';
$page     = max(1, (int)$getStr('page'));

$where = $baseWhere;
$params = [];
if ($filterStatus === 'Pending')  $where .= " AND $SQL_P";
if ($filterStatus === 'Verified') $where .= " AND $SQL_V";
if ($filterStatus === 'Rejected') $where .= " AND $SQL_R";

$methodSets = [
    'cash'    => "('cash', 'over-the-counter (treasurer)')",
    'ewallet' => "('gcash', 'maya', 'online', 'e-wallet')",
    'bank'    => "('bank', 'bank transfer', 'online bank transfer')",
];
if ($fMethod !== 'all') $where .= " AND LOWER(p.payment_method::text) IN " . $methodSets[$fMethod];
if ($fPurpose !== 'all' && $hasPurposeColumn) { $where .= " AND p.payment_purpose = :fpurpose"; $params[':fpurpose'] = $fPurpose; }
if ($fFrom !== '') { $where .= " AND p.payment_date::date >= :dfrom"; $params[':dfrom'] = $fFrom; }
if ($fTo !== '')   { $where .= " AND p.payment_date::date <= :dto";   $params[':dto'] = $fTo; }

if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $conds = ["r.business_name ILIKE :s0", "r.tracking_no ILIKE :s1", "u.fullname ILIKE :s2"];
    $params[':s0'] = $params[':s1'] = $params[':s2'] = $like;
    foreach ($refCols as $i => $c) { $conds[] = "p.$c::text ILIKE :sr$i"; $params[":sr$i"] = $like; }
    $where .= " AND (" . implode(' OR ', $conds) . ")";
}
$hasActiveFilters = ($filterStatus !== 'all' || $search !== '' || $fMethod !== 'all' || $fPurpose !== 'all' || $fFrom !== '' || $fTo !== '' || $fSort !== 'queue');

$qsBase = array_filter([
    'status' => $filterStatus !== 'all' ? $filterStatus : '',
    'search' => $search,
    'method' => $fMethod !== 'all' ? $fMethod : '',
    'purpose' => $fPurpose !== 'all' ? $fPurpose : '',
    'from' => $fFrom, 'to' => $fTo,
    'sort' => $fSort !== 'queue' ? $fSort : '',
], function ($v) { return $v !== '' && $v !== null; });
$pageUrl = function (array $extra = []) use ($qsBase) {
    $qs = http_build_query(array_merge($qsBase, $extra));
    return 'payments.php' . ($qs !== '' ? '?' . $qs : '');
};

$orderMap = [
    'queue'       => "CASE WHEN $SQL_P THEN 0 ELSE 1 END, CASE WHEN $SQL_P THEN $tsCol END ASC, $tsCol DESC",
    'newest'      => "$tsCol DESC",
    'oldest'      => "$tsCol ASC",
    'amount_desc' => "p.amount_paid DESC, p.id DESC",
    'amount_asc'  => "p.amount_paid ASC, p.id ASC",
];

/* CSV EXPORT (sumusunod sa kasalukuyang filters) */
if ($getStr('export') === 'csv') {
    $csvSafe = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; };   // iwas formula injection
    $rowsX = [];
    try {
        $vJoin = $hasVerifiedBy ? " LEFT JOIN users vu ON vu.id = p.verified_by" : "";
        $vSel  = $hasVerifiedBy ? ", vu.fullname AS verifier_name" : "";
        $sx = $conn->prepare("SELECT p.*, r.business_name, r.tracking_no, u.fullname AS owner_name, u.email AS owner_email $vSel $joinBase $vJoin WHERE $where ORDER BY " . $orderMap[$fSort] . " LIMIT 5000");
        $sx->execute($params);
        $rowsX = $sx->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log('[staff/payments] export: ' . $e->getMessage()); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference / OR No.', 'Owner', 'Email', 'Business', 'Tracking No.', 'Purpose', 'Amount (PHP)', 'Payment Date', 'Method', 'Status', 'Submitted At', 'Verified At', 'Verified By', 'Reject Reason']);
    foreach ($rowsX as $x) {
        $refX = '';
        foreach ($refCols as $rc) { if (!empty($x[$rc])) { $refX = $x[$rc]; break; } }
        fputcsv($out, [
            $csvSafe($refX), $csvSafe($x['owner_name'] ?? ''), $csvSafe($x['owner_email'] ?? ''),
            $csvSafe($x['business_name'] ?? ''), $csvSafe($x['tracking_no'] ?? ''),
            $csvSafe($x['payment_purpose'] ?? ($x['fee_type'] ?? '')),
            number_format((float)($x['amount_paid'] ?? 0), 2, '.', ''),
            !empty($x['payment_date']) ? date('Y-m-d', strtotime($x['payment_date'])) : '',
            staffMethodGroup($x['payment_method'] ?? ''),
            (string)($x['status'] ?? ''),
            !empty($x['created_at']) ? date('Y-m-d H:i', strtotime($x['created_at'])) : '',
            !empty($x['verified_at']) ? date('Y-m-d H:i', strtotime($x['verified_at'])) : '',
            $csvSafe($x['verifier_name'] ?? ''),
            $csvSafe($x['reject_reason'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

/* DAILY COLLECTION REPORT (printable) */
if ($getStr('report') === 'daily') {
    $rd = $isYmd($getStr('date')) ? $getStr('date') : date('Y-m-d');
    $repRows = [];
    try {
        $sr = $conn->prepare("SELECT p.*, r.business_name, r.tracking_no, u.fullname AS owner_name $joinBase WHERE $baseWhere AND $SQL_V AND $dateExpr = :rd ORDER BY p.payment_date, p.id");
        $sr->execute([':rd' => $rd]);
        $repRows = $sr->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log('[staff/payments] report: ' . $e->getMessage()); }
    $byMethod = []; $grand = 0.0;
    foreach ($repRows as $rr) {
        $g = staffMethodGroup($rr['payment_method'] ?? '');
        $byMethod[$g] = ($byMethod[$g] ?? 0) + (float)($rr['amount_paid'] ?? 0);
        $grand += (float)($rr['amount_paid'] ?? 0);
    }
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Daily Collection Report - <?php echo h($rd); ?></title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; margin: 28px; font-size: 13px; }
    h1 { font-size: 20px; margin: 0; color: #991b1b; } h2 { font-size: 14px; font-weight: 600; margin: 2px 0 14px; color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 18px; } th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-size: 12px; } td.num, th.num { text-align: right; }
    .sum { width: 50%; } .sig { display: flex; gap: 60px; margin-top: 50px; } .sig div { flex: 1; border-top: 1px solid #0f172a; padding-top: 4px; text-align: center; font-size: 12px; }
    .noprint { margin-bottom: 16px; } @media print { .noprint { display: none; } body { margin: 12px; } }
</style></head><body>
<div class="noprint"><button onclick="window.print()">Print / Save as PDF</button></div>
<h1>RENTRACK LGU</h1>
<h2>Daily Collection Report &mdash; <?php echo h(date('F d, Y', strtotime($rd))); ?></h2>
<table>
    <thead><tr><th>#</th><th>Reference / OR</th><th>Payer / Business</th><th>Purpose</th><th>Method</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php if (empty($repRows)): ?>
        <tr><td colspan="6" style="text-align:center; color:#64748b;">No verified collections for this date.</td></tr>
    <?php else: foreach ($repRows as $i => $rr):
        $rf = ''; foreach ($refCols as $rc) { if (!empty($rr[$rc])) { $rf = $rr[$rc]; break; } } ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td><?php echo h($rf); ?></td>
            <td><?php echo h($rr['owner_name'] ?? ''); ?><br><small><?php echo h($rr['business_name'] ?? ''); ?> (<?php echo h($rr['tracking_no'] ?? ''); ?>)</small></td>
            <td><?php echo h($rr['payment_purpose'] ?? ($rr['fee_type'] ?? '')); ?></td>
            <td><?php echo h(staffMethodGroup($rr['payment_method'] ?? '')); ?></td>
            <td class="num"><?php echo h(formatCurrency($rr['amount_paid'] ?? 0)); ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<table class="sum">
    <thead><tr><th>Summary by method</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($byMethod as $mName => $mAmt): ?>
        <tr><td><?php echo h($mName); ?></td><td class="num"><?php echo h(formatCurrency($mAmt)); ?></td></tr>
    <?php endforeach; ?>
        <tr><th>TOTAL COLLECTIONS (<?php echo count($repRows); ?> payment<?php echo count($repRows) === 1 ? '' : 's'; ?>)</th><th class="num"><?php echo h(formatCurrency($grand)); ?></th></tr>
    </tbody>
</table>
<p style="font-size:11px; color:#64748b;">Prepared by <?php echo h($staffName); ?> on <?php echo h(date('M d, Y h:i A')); ?>. Includes only payments with status Verified/Paid.</p>
<div class="sig"><div>Prepared by: <?php echo h($staffName); ?></div><div>Checked by</div><div>Noted by: Municipal Treasurer</div></div>
</body></html>
<?php
    exit;
}

/* LIST + PAGINATION */
$perPage = 15;
$totalRows = 0;
try {
    $sc = $conn->prepare("SELECT COUNT(*) $joinBase WHERE $where");
    $sc->execute($params);
    $totalRows = (int)$sc->fetchColumn();
} catch (PDOException $e) { error_log('[staff/payments] count: ' . $e->getMessage()); }
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$payments = [];
if (tableExists($conn, 'payments')) {
    $selectFields = "p.*, r.business_name, r.tracking_no, u.fullname AS owner_name, u.email AS owner_email";
    $leftJoins = "";
    if ($hasRenewalIdColumn && tableExists($conn, 'permit_renewals')) {
        $selectFields .= ", pr.document_type AS renewal_doc_type, pr.id AS linked_renewal_id";
        $leftJoins .= " LEFT JOIN permit_renewals pr ON p.renewal_id = pr.id ";
    }
    if ($hasVerifiedBy) {
        $selectFields .= ", vu.fullname AS verifier_name";
        $leftJoins .= " LEFT JOIN users vu ON vu.id = p.verified_by ";
    }
    $selectFields .= $hasCreatedAt ? ", ROUND(EXTRACT(EPOCH FROM (NOW() - p.created_at)) / 3600) AS age_hours" : ", NULL AS age_hours";
    if (!empty($refCols)) {
        $selectFields .= ", (SELECT COUNT(*) FROM payments p2 WHERE p2.id <> p.id AND " . $refSql('p2') . " <> '' AND LOWER(" . $refSql('p2') . ") = LOWER(" . $refSql('p') . ") AND LOWER(p2.status::text) NOT IN ('rejected', 'cancelled')) AS dup_count";
    } else {
        $selectFields .= ", 0 AS dup_count";
    }
    try {
        $stmt = $conn->prepare("SELECT $selectFields $joinBase $leftJoins WHERE $where ORDER BY " . $orderMap[$fSort] . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log('[staff/payments] list: ' . $e->getMessage()); }
}
$showFrom = $totalRows > 0 ? $offset + 1 : 0;
$showTo   = min($offset + $perPage, $totalRows);

// ==========================
// SUMMARY (hindi apektado ng filters)
// ==========================
$pendingCount = 0; $verifiedCount = 0; $totalCount = 0; $rejectedCount = 0;
$pendingAmount = 0.0; $todayAmount = 0.0; $todayCount = 0; $monthAmount = 0.0; $overduePending = 0;
if (tableExists($conn, 'payments')) {
    $overdueExpr = $hasCreatedAt ? "COUNT(*) FILTER (WHERE $SQL_P AND p.created_at <= NOW() - INTERVAL '3 days')" : "0";
    try {
        $ss = $conn->query("
            SELECT COUNT(*) AS cnt,
                   COUNT(*) FILTER (WHERE $SQL_V) AS vcnt,
                   COUNT(*) FILTER (WHERE $SQL_P) AS pcnt,
                   COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_P), 0) AS pamt,
                   COUNT(*) FILTER (WHERE $SQL_R) AS rcnt,
                   COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_V AND $dateExpr = CURRENT_DATE), 0) AS today_amt,
                   COUNT(*) FILTER (WHERE $SQL_V AND $dateExpr = CURRENT_DATE) AS today_cnt,
                   COALESCE(SUM(p.amount_paid) FILTER (WHERE $SQL_V AND DATE_TRUNC('month', $dateExpr) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS month_amt,
                   $overdueExpr AS overdue
            $joinBase WHERE $baseWhere
        ");
        $sum = $ss ? ($ss->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $totalCount    = (int)($sum['cnt'] ?? 0);
        $verifiedCount = (int)($sum['vcnt'] ?? 0);
        $pendingCount  = (int)($sum['pcnt'] ?? 0);
        $pendingAmount = (float)($sum['pamt'] ?? 0);
        $rejectedCount = (int)($sum['rcnt'] ?? 0);
        $todayAmount   = (float)($sum['today_amt'] ?? 0);
        $todayCount    = (int)($sum['today_cnt'] ?? 0);
        $monthAmount   = (float)($sum['month_amt'] ?? 0);
        $overduePending = (int)($sum['overdue'] ?? 0);
    } catch (PDOException $e) { error_log('[staff/payments] summary: ' . $e->getMessage()); }
}

/* CHART DATA: verified collections (6 buwan) at hati ayon sa method */
$chartMonthLabels = []; $chartMonthValues = []; $hasMonthlyData = false;
$chartMethodLabels = []; $chartMethodValues = []; $hasMethodData = false;
$mMap = [];
try {
    $sm = $conn->query("SELECT TO_CHAR($dateExpr, 'YYYY-MM') AS mk, COALESCE(SUM(p.amount_paid), 0) AS total $joinBase
                        WHERE $baseWhere AND $SQL_V AND $dateExpr >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
                        GROUP BY TO_CHAR($dateExpr, 'YYYY-MM')");
    foreach (($sm ? $sm->fetchAll(PDO::FETCH_ASSOC) : []) as $mr) $mMap[$mr['mk']] = (float)$mr['total'];
} catch (PDOException $e) { $mMap = []; }
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $chartMonthLabels[] = date('M', $ts);
    $v = $mMap[date('Y-m', $ts)] ?? 0;
    $chartMonthValues[] = $v;
    if ($v > 0) $hasMonthlyData = true;
}
try {
    $smt = $conn->query("SELECT CASE
                             WHEN LOWER(p.payment_method::text) IN ('gcash', 'maya', 'online', 'e-wallet') THEN 'E-Wallet'
                             WHEN LOWER(p.payment_method::text) IN ('bank', 'bank transfer', 'online bank transfer') THEN 'Bank Transfer'
                             WHEN LOWER(p.payment_method::text) IN ('cash', 'over-the-counter (treasurer)') THEN 'Cash'
                             ELSE 'Other' END AS m,
                           COALESCE(SUM(p.amount_paid), 0) AS total $joinBase
                         WHERE $baseWhere AND $SQL_V AND $dateExpr >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
                         GROUP BY 1 ORDER BY total DESC");
    foreach (($smt ? $smt->fetchAll(PDO::FETCH_ASSOC) : []) as $mt) {
        $chartMethodLabels[] = $mt['m'];
        $chartMethodValues[] = (float)$mt['total'];
        if ((float)$mt['total'] > 0) $hasMethodData = true;
    }
} catch (PDOException $e) { /* walang chart */ }

$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) {
        $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
    }
}

// ✅ Financial counts for navbar
$pendingPayments = 0; $verifiedPayments = 0; $totalPayments = 0;
$pendingRemittances = 0; $totalRemittances = 0;

if (tableExists($conn, 'payments')) {
    $q1 = $conn->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'");
    if ($q1) $pendingPayments = (int)$q1->fetchColumn();
    $q2 = $conn->query("SELECT COUNT(*) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q2) $verifiedPayments = (int)$q2->fetchColumn();
    $q3 = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q3) $totalPayments = (float)$q3->fetchColumn();
}
if (tableExists($conn, 'remittances')) {
    $q4 = $conn->query("SELECT COUNT(*) FROM remittances WHERE status = 'Pending'");
    if ($q4) $pendingRemittances = (int)$q4->fetchColumn();
    $q5 = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM remittances WHERE status = 'Accepted'");
    if ($q5) $totalRemittances = (float)$q5->fetchColumn();
}

$notificationCount = 0;
$notifications = [];

if (tableExists($conn, 'notifications')) {
    $notifStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifStmt->execute([':sid' => $staffId]);
    $notificationCount = (int) $notifStmt->fetchColumn();

    $notifListStmt = $conn->prepare("
        SELECT id, title, message, created_at 
        FROM notifications 
        WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $notifListStmt->execute([':sid' => $staffId]);
    $notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Payments | RENTRACK LGU</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --border-glass: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;

            --primary-red: #dc2626;
            --primary-red-dark: #991b1b;
            --primary-red-light: #fecdd3;
            --red-soft: #fee2e2;

            --ok: #059669; --ok-bg: #ecfdf5; --ok-bd: #a7f3d0;
            --warn: #b45309; --warn-bg: #fffbeb; --warn-bd: #fde68a;
            --danger: #b91c1c; --danger-bg: #fef2f2; --danger-bd: #fecaca;
            --info: #1d4ed8; --info-bg: #eff6ff; --info-bd: #dbeafe;
            --purple: #6d28d9; --purple-bg: #f5f3ff; --purple-bd: #ddd6fe;

            --r-xs: 6px; --r-sm: 8px; --r-md: 10px; --r-lg: 12px; --r-xl: 16px;
            --radius: 20px;

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

        @keyframes borderSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important; border: 0 !important;
            height: 100%; font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
        }

        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.45) #f1f5f9; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 20px; }
        ::-webkit-scrollbar-thumb { background: var(--primary-red); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .page-container {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            width: 100%;
            margin: 0 !important; padding: 0 !important;
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
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

        .main-wrapper {
            flex: 1; display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            margin: 0 !important; padding: 0 !important;
            background: #f8fafc;
        }

        .sticky-header {
            position: sticky; top: 0;
            z-index: 2000; /* ✅ UPDATED: Mas mataas kaysa overlay (1999) */
            background: #ffffff;
        }

        .top-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 18px; background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            margin: 0 !important; gap: 14px;
            flex-wrap: nowrap; min-height: 58px;
        }

        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }

        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring {
            position: absolute; inset: -2px; border-radius: 50%;
            border: 2px dashed rgba(220, 38, 38, 0.55);
            animation: borderSpin 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--primary-red); line-height: 1.1; margin: 0; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none; width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f8fafc; border: 1px solid #e2e8f0;
            color: var(--primary-red); cursor: pointer;
            font-size: 16px; flex-shrink: 0;
        }
        .menu-toggle:active { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

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
            padding: 10px 10px; color: #334155; text-decoration: none;
            font-size: 12.5px; font-weight: 600; border-radius: 8px;
            transition: all 0.15s ease; cursor: pointer;
            white-space: nowrap; background: transparent;
            border: none; font-family: inherit;
        }

        .nav-link i.nav-ico { color: var(--primary-red); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--primary-red); background: #fff1f2; }
        .nav-link.active { color: var(--primary-red); background: #fff1f2; font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--primary-red); background: #fff1f2; }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-link .menu-badge {
            background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 999px;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.55);
            animation: pulseBadge 2s ease-in-out infinite;
        }
        @keyframes pulseBadge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

        .nav-dropdown {
            position: absolute; top: calc(100% + 6px); left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.10);
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
        .nav-dropdown a:hover { background: #fff1f2; color: var(--primary-red); }
        .nav-dropdown a i { width: 16px; color: var(--primary-red); font-size: 14px; text-align: center; flex-shrink: 0; }
        .nav-dropdown a .menu-badge {
            margin-left: auto; background: #fbbf24; color: #7c2d12;
            font-size: 10px; font-weight: 700;
            padding: 1px 8px; border-radius: 999px;
        }

        .mobile-drawer-header { display: none; }

        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px;
            padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; }
        .search-container i {
            position: absolute; left: 13px; top: 50%;
            transform: translateY(-50%);
            color: var(--primary-red); z-index: 2; font-size: 13px;
        }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid #e2e8f0;
            background: #f8fafc; color: #0f172a;
            font-size: 13px; outline: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-container:hover input { border-color: var(--primary-red); background: var(--red-soft); }

        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
        }
        .nav-clock .time { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: var(--primary-red); }
        .nav-clock .date { font-size: 10px; color: #64748b; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid #e2e8f0;
            color: var(--primary-red); cursor: pointer;
            position: relative; flex-shrink: 0; text-decoration: none;
        }
        .nav-icon:active { background: #f8fafc; }
        .nav-icon .badge {
            position: absolute; top: -4px; right: -4px;
            background: #ef4444; color: #fff;
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
            border: 1px solid #e2e8f0; cursor: pointer;
        }
        .profile-btn:hover { background: #f8fafc; }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary-red);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px; overflow: hidden;
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--primary-red); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            width: 240px; background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
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
        .profile-dropdown .dropdown-item:hover { background: var(--red-soft); color: var(--primary-red); }
        .profile-dropdown .dropdown-item.danger { color: #dc2626; }

        .notification-title { padding: 14px 20px; font-weight: 600; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .notification-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; }
        .notification-item strong { display: block; font-size: 13px; margin-bottom: 2px; color: var(--primary-red); }
        .notification-item p { font-size: 12px; color: #475569; margin: 0; }
        .notification-item small { display: block; font-size: 10px; color: #94a3b8; margin-top: 4px; }

        /* CONTENT */
        .content {
            flex: 1; padding: 24px 28px 20px;
            padding-bottom: calc(20px + var(--safe-bottom));
            overflow-y: auto; background: #f8fafc;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: var(--text-muted);
            margin-bottom: 14px; flex-wrap: wrap;
        }
        .breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color 0.15s; }
        .breadcrumb a:hover { color: var(--primary-red); }
        .breadcrumb strong { color: var(--text-primary); font-weight: 600; }
        .breadcrumb .sep { color: var(--text-muted); font-size: 10px; }

        .page-header {
            position: relative; overflow: hidden;
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--radius); padding: 20px 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
            box-shadow: var(--sh-sm);
        }
        .page-header::before {
            content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
            background: linear-gradient(180deg, #ef4444, #b91c1c);
        }
        .page-header::after {
            content: ''; position: absolute; right: -50px; top: -50px;
            width: 180px; height: 180px; border-radius: 50%;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.05), transparent 70%);
            pointer-events: none;
        }
        .page-header > * { position: relative; z-index: 1; }
        .page-header h1 {
            color: var(--text-primary); font-size: 18px; font-weight: 700;
            letter-spacing: -0.01em; line-height: 1.3;
            display: flex; align-items: center; gap: 10px; margin-bottom: 3px;
        }
        .page-header h1 i { color: var(--primary-red); font-size: 16px; }
        .page-header p { color: var(--text-muted); font-size: 13px; }
        @media (min-width: 768px) {
            .page-header { padding: 22px 26px; margin-bottom: 20px; }
            .page-header h1 { font-size: 22px; }
            .page-header p { font-size: 14px; }
        }
        .date-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
            border-radius: 999px;
            color: var(--primary-red);
            font-size: 12px; font-weight: 600;
            white-space: nowrap;
        }
        .date-badge i { color: var(--primary-red); font-size: 12px; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media (min-width: 576px) { .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; } }

        .stat-card {
            display: flex; align-items: center; justify-content: space-between;
            border-radius: var(--r-lg); padding: 16px 18px;
            transition: var(--transition); box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            color: #fff; position: relative; overflow: hidden; cursor: pointer;
            text-decoration: none;
        }
        @media (min-width: 768px) { .stat-card { padding: 18px 20px; } }
        .stat-card::after {
            content: ''; position: absolute; top: -20px; right: -20px;
            width: 100px; height: 100px; border-radius: 50%;
            background: rgba(255,255,255,0.15); pointer-events: none;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .stat-card.bg-amber { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-card.bg-green { background: linear-gradient(135deg, #dc2626, #991b1b); }
        .stat-card.bg-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }

        .stat-card .label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: rgba(255,255,255,0.9);
            margin-bottom: 4px; display: block;
        }
        .stat-card .value { font-size: 22px; font-weight: 800; color: #fff; }
        @media (min-width: 768px) { .stat-card .value { font-size: 26px; } }
        .stat-card i { font-size: 22px; color: rgba(255,255,255,0.9); z-index: 1; }
        @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

        /* FILTER CARD */
        .filter-card {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--r-lg); padding: 14px;
            margin-bottom: 20px; box-shadow: var(--sh-sm);
        }
        @media (min-width: 768px) { .filter-card { padding: 16px 18px; margin-bottom: 24px; } }
        .filter-left { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }

        .search-box {
            flex: 1 1 100%; display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border: 1px solid #e2e8f0;
            border-radius: var(--r-lg); background: #f8fafc;
            transition: var(--transition);
        }
        @media (min-width: 576px) { .search-box { flex: 1 1 260px; } }
        .search-box:focus-within { border-color: var(--primary-red); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }
        .search-box i { color: #94a3b8; font-size: 13px; }
        .search-box input {
            border: none; background: transparent; outline: none; width: 100%;
            font-family: inherit; font-size: 13.5px; color: #0f172a;
        }
        .search-box input::placeholder { color: #94a3b8; }

        .filter-left select {
            flex: 1 1 100%; padding: 10px 14px;
            border: 1px solid #e2e8f0; border-radius: var(--r-lg);
            background: #f8fafc; font-family: inherit;
            font-size: 13.5px; color: #0f172a;
            cursor: pointer; outline: none; transition: var(--transition);
        }
        @media (min-width: 576px) { .filter-left select { flex: 0 0 auto; min-width: 150px; } }
        .filter-left select:focus { border-color: var(--primary-red); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

        .btn-filter {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 10px 18px; font-size: 13.5px; font-weight: 600;
            border-radius: var(--r-lg); border: none; cursor: pointer;
            background: var(--primary-red); color: #fff;
            transition: var(--transition); flex: 1 1 100%;
            font-family: inherit;
        }
        @media (min-width: 576px) { .btn-filter { flex: 0 0 auto; } }
        .btn-filter:hover { background: var(--primary-red-dark); }

        .btn-filter-clear {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 10px 18px; font-size: 13.5px; font-weight: 600;
            border-radius: var(--r-lg); border: 1px solid #e2e8f0;
            background: #ffffff; color: #475569; text-decoration: none;
            flex: 1 1 100%;
        }
        @media (min-width: 576px) { .btn-filter-clear { flex: 0 0 auto; } }
        .btn-filter-clear:hover { background: #f8fafc; color: var(--primary-red); border-color: var(--primary-red-light); }

        /* PANEL */
        .panel {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--radius); box-shadow: var(--sh-sm);
            overflow: hidden; margin-bottom: 24px;
        }
        .panel-head {
            padding: 16px 22px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .panel-title {
            font-size: 14.5px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 9px;
        }
        .panel-title i { color: var(--primary-red); font-size: 14px; }
        .panel-meta { font-size: 12px; color: var(--text-muted); }
        .panel-meta strong { color: var(--text-primary); font-weight: 700; }

        /* TABLE */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-wrap::-webkit-scrollbar { height: 6px; }
        .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; margin-bottom: 0; }
        .table thead th {
            text-align: left; padding: 12px 14px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-muted); background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0; white-space: nowrap;
        }
        .table tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-secondary);
            vertical-align: middle; font-weight: 500;
        }
        .table tbody tr { transition: background 0.15s; }
        .table tbody tr:hover { background: #fff1f2; }
        .table tbody tr:last-child td { border-bottom: none; }

        .tracking {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700; font-size: 11.5px;
            color: #000000; /* ✅ CHANGED TO BLACK */
            background: var(--red-soft);
            padding: 4px 10px; border-radius: 6px;
            display: inline-block; border: 1px solid var(--primary-red-light);
        }

        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; white-space: nowrap;
        }
        .badge-success { background: var(--ok-bg); color: #047857; border: 1px solid var(--ok-bd); }
        .badge-warning { background: var(--warn-bg); color: var(--warn); border: 1px solid var(--warn-bd); }
        .badge-secondary { background: #f1f5f9; color: var(--text-muted); border: 1px solid #e2e8f0; }
        .badge-gcash { background: var(--info-bg); color: var(--info); border: 1px solid var(--info-bd); }
        .badge-maya { background: var(--ok-bg); color: #16a34a; border: 1px solid var(--ok-bd); }
        .badge-bank { background: #ccfbf1; color: #0d9488; border: 1px solid #99f6e4; }
        .badge-card { background: var(--purple-bg); color: var(--purple); border: 1px solid var(--purple-bd); }
        .badge-check { background: var(--warn-bg); color: var(--warn); border: 1px solid var(--warn-bd); }

        /* ✅ UPDATED: Badges para sa Payment Purpose ay itim ang text */
        .badge-purpose-renewal { background: var(--info-bg); color: #000000; border: 1px solid var(--info-bd); font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block; }
        .badge-purpose-new { background: var(--red-soft); color: #000000; border: 1px solid var(--primary-red-light); font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block; }
        .badge-purpose-inspection { background: var(--warn-bg); color: #000000; border: 1px solid var(--warn-bd); font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block; }
        .badge-purpose-penalty { background: var(--danger-bg); color: #000000; border: 1px solid var(--danger-bd); font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block; }

        .action-cell { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }
        .btn-view, .btn-verify, .btn-reject {
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
            padding: 6px 12px; font-size: 11.5px; font-weight: 600;
            border-radius: 8px; border: 1px solid transparent;
            cursor: pointer; transition: 0.2s; text-decoration: none;
            font-family: inherit;
        }
        .btn-view { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
        .btn-view:hover { background: #e2e8f0; color: #0f172a; }
        .btn-verify { background: var(--red-soft); border-color: var(--primary-red-light); color: var(--primary-red); }
        .btn-verify:hover { background: var(--primary-red); color: #fff; }
        .btn-reject { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
        .btn-reject:hover { background: #dc2626; color: #fff; }

        /* DETAIL MODAL */
        .detail-row {
            display: flex; justify-content: space-between; gap: 16px;
            padding: 10px 0; border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-row .detail-label { color: #64748b; flex-shrink: 0; }
        .detail-row .detail-value { color: #0f172a; font-weight: 600; text-align: right; word-break: break-word; }

        .proof-preview-box {
            margin-top: 10px; border: 1px solid #e2e8f0;
            border-radius: 12px; padding: 12px;
            background: #f8fafc; text-align: center;
        }
        .proof-preview-box img {
            max-width: 100%; max-height: 280px;
            border-radius: 8px; cursor: pointer;
            transition: transform 0.2s;
        }
        .proof-preview-box img:hover { transform: scale(1.02); }
        .proof-hint { font-size: 11px; color: var(--primary-red); margin-top: 6px; display: block; font-weight: 600; }
        .proof-empty { color: #94a3b8; font-size: 12.5px; font-style: italic; text-align: center; padding: 16px; }

        .empty-state { text-align: center; padding: 40px 16px !important; color: #64748b; }
        .empty-state i { font-size: 48px; color: #cbd5e1; display: block; margin-bottom: 16px; }
        .empty-state h4 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0; }
        .empty-state p { margin: 0; font-size: 14px; }

        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: #64748b;
            flex-wrap: wrap; gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .modal-content {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
        }
        @media (max-width: 576px) {
            .modal-dialog { margin: 8px; max-width: calc(100% - 16px); }
            .modal-body { max-height: 70vh; overflow-y: auto; }
        }

        /* RESPONSIVE */
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
            .profile-btn .chevron { display: none; }
            .menu-toggle { display: flex; }

            .search-wrapper {
                order: 5; width: 100%;
                padding: 0; margin-top: 4px; margin-bottom: 4px;
                max-width: 100%; flex-basis: 100%;
            }
            .search-container { max-width: 100%; }

            .main-nav-bar {
                position: fixed; top: 0; left: -100%;
                width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh;
                flex-direction: column;
                align-items: stretch; justify-content: flex-start;
                padding: 0;
                z-index: 2001; /* ✅ UPDATED: Pinakamataas para sa menu mismo */
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
                background: #ffffff; color: var(--primary-red);
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
                background: #f8fafc; margin-top: 4px;
                border-radius: 10px; min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999; /* ✅ Nasa ilalim ng header at menu */
                backdrop-filter: blur(3px);
                -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
            }
            .sidebar-overlay.active { display: block; opacity: 1; }

            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
            .profile-dropdown { width: 220px; }
        }

        @media (max-width: 768px) {
            .content {
                padding: 16px 14px;
                padding-bottom: calc(24px + var(--safe-bottom));
            }
            .page-header {
                flex-direction: column; align-items: flex-start;
                gap: 10px; margin-bottom: 16px;
                padding: 16px !important;
            }
            .page-header h1 { font-size: 18px; }
            .page-header p { font-size: 12.5px; }
            .date-badge { width: 100%; justify-content: center; font-size: 12px; }

            .stats-grid { grid-template-columns: 1fr; gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .label { font-size: 9.5px; }
            .stat-card .value { font-size: 20px; }
            .stat-card i { font-size: 18px; }

            .panel { border-radius: 16px; }
            .panel-head { padding: 14px 16px; }
            .panel-title { font-size: 13.5px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }

            /* Mobile card table */
            .table-wrap { overflow-x: visible; }
            .table { min-width: 100% !important; }
            .table thead { display: none; }
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            .table tr {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-left: 3px solid var(--primary-red) !important;
                border-radius: var(--r-lg);
                margin: 0 0 12px;
                padding: 14px; box-shadow: var(--sh-xs);
            }
            .table tbody tr:hover { background: #ffffff !important; }
            .table td {
                display: flex; align-items: center; justify-content: space-between;
                text-align: right;
                padding: 8px 0 !important;
                border-bottom: 1px dashed #eef1f6 !important;
                white-space: normal; gap: 12px;
            }
            .table td:last-child {
                border-bottom: none !important;
                flex-direction: column; align-items: stretch;
                padding-top: 14px !important;
                padding-bottom: 2px !important;
            }
            .table td::before {
                content: attr(data-label);
                font-weight: 700; font-size: 10px;
                color: var(--text-muted);
                text-transform: uppercase; letter-spacing: 0.05em;
                text-align: left; flex-shrink: 0;
            }
            .table td:last-child::before { display: none; }
            .action-cell { justify-content: stretch; }
            .action-cell .btn-view,
            .action-cell .btn-verify,
            .action-cell .btn-reject { flex: 1; }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .date-badge { padding: 5px 10px; font-size: 11px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card i { font-size: 18px; }
            .footer { font-size: 10.5px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input,
            .search-box input,
            .filter-left select { font-size: 16px !important; }
        }

        /* ===== STAFF PAYMENTS: NEW UI ===== */
        .stat-card .sub { display: block; font-size: 11px; color: rgba(255,255,255,0.88); font-weight: 600; margin-top: 2px; }
        .stats-grid + .stats-grid { margin-top: -8px; }
        .charts-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; margin-bottom: 20px; }
        .chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: var(--r-lg); padding: 16px; box-shadow: var(--sh-sm); }
        .chart-card h4 { font-size: 13px; font-weight: 700; color: #0f172a; margin: 0 0 10px; }
        .chart-box { position: relative; height: 230px; }
        .chart-empty { position: absolute; inset: 0; z-index: 5; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,0.92); color: #64748b; font-size: 12.5px; font-weight: 600; }
        .chart-empty i { font-size: 24px; color: #f87171; margin-bottom: 6px; }
        .filter-left input[type="date"] { padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: var(--r-lg); background: #f8fafc; font-family: inherit; font-size: 13px; color: #0f172a; outline: none; }
        .filter-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0; }
        .filter-tools form { display: inline-flex; gap: 6px; align-items: center; margin: 0; }
        .filter-tools input[type="date"] { padding: 7px 10px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 12.5px; background: #fff; }
        .tool-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 12.5px; font-weight: 700; color: #334155; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; text-decoration: none; cursor: pointer; font-family: inherit; }
        .tool-btn:hover { background: #fef2f2; color: var(--primary-red); border-color: #fecaca; }
        .flag { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; text-transform: uppercase; white-space: nowrap; }
        .flag-dup { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .flag-age { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .flag-age.warn { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .flag-age.old { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .row-note { display: block; margin-top: 4px; font-size: 10.5px; color: #64748b; line-height: 1.35; max-width: 220px; white-space: normal; }
        .row-note.bad { color: #b91c1c; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: var(--r-lg); padding: 12px 16px; margin-top: 16px; box-shadow: var(--sh-sm); }
        .pager-info { font-size: 12px; color: #64748b; font-weight: 600; }
        .pager-links { display: flex; gap: 6px; }
        .pager-links a { font-size: 12px; font-weight: 700; padding: 5px 11px; border-radius: 8px; border: 1px solid #e2e8f0; color: #334155; text-decoration: none; background: #fff; }
        .pager-links a:hover { background: #fef2f2; }
        .pager-links a.active { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }
        @media (max-width: 992px) { .charts-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="page-container">
        <!-- OFFICIAL HEADER -->
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
                        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
                        <div class="nav-brand">
                            <div class="brand-logo">
                                <div class="brand-logo-ring"></div>
                                <div class="brand-logo-circle">RT</div>
                            </div>
                            <div class="brand-text">
                                <h2>RENTRACK</h2>
                                <span>LGU Staff</span>
                            </div>
                        </div>
                    </div>

                    <nav class="main-nav-bar" id="mainNavBar">
                        <div class="mobile-drawer-header">
                            <div class="drawer-brand">
                                <div class="drawer-brand-logo">RT</div>
                                <div class="drawer-brand-text">
                                    <h2>RENTRACK</h2>
                                    <span>LGU Staff</span>
                                </div>
                            </div>
                            <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close menu">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <ul class="nav-menu">
                            <li class="nav-item">
                                <a href="dashboard.php" class="nav-link">
                                    <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                                </a>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-folder-open nav-ico"></i> Rental Management
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="rentals.php"><i class="fa-solid fa-folder-open"></i> Rental Applications</a>
                                    <a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i> Register Rental</a>
                                    <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                    <a href="renewals_approval.php">
                                        <i class="fa-solid fa-file-circle-exclamation"></i> Permit Renewals
                                        <?php if ($pendingRenewalsCount > 0): ?>
                                            <span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="reports.php"><i class="fa-solid fa-file-invoice"></i> Reports &amp; Analytics</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-clipboard-check nav-ico"></i> Inspections
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> My Inspections</a>
                                    <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-days"></i> Inspection Schedule</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link active" data-dropdown>
                                    <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                    <?php if ($pendingRemittances > 0): ?>
                                        <span class="menu-badge"><?php echo $pendingRemittances; ?></span>
                                    <?php endif; ?>
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i> Payments</a>
                                    
                                        <?php if ($pendingRemittances > 0): ?>
                                            <span class="menu-badge"><?php echo $pendingRemittances; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </nav>

                    <div class="search-wrapper">
                        <div class="search-container" data-bs-toggle="modal" data-bs-target="#searchModal" style="cursor: pointer;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="globalSearchInput" placeholder="Search OR, business, owner..." autocomplete="off" readonly style="cursor: pointer;">
                        </div>
                    </div>

                    <div class="navbar-right">
                        <div class="nav-clock">
                            <div class="time" id="clockTime">12:00 PM</div>
                            <div class="date"><?php echo date("M d, Y"); ?></div>
                        </div>

                        <div class="nav-icon-wrapper" style="position:relative;">
                            <button class="nav-icon" id="notifBtn" aria-label="Notifications">
                                <i class="fa-regular fa-bell"></i>
                                <?php if ($notificationCount > 0): ?>
                                    <span class="badge"><?php echo $notificationCount; ?></span>
                                <?php endif; ?>
                            </button>
                            <div id="notifDropdown" class="notification-dropdown">
                                <div class="notification-title">Recent Notifications</div>
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $n): ?>
                                        <div class="notification-item">
                                            <strong><?php echo htmlspecialchars($n['title'] ?? 'Notification'); ?></strong>
                                            <p><?php echo htmlspecialchars($n['message'] ?? ''); ?></p>
                                            <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-item" style="text-align:center;color:#64748b;padding:20px;">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="profile-wrapper">
                            <div class="profile-btn" id="profileToggle">
                                <div class="avatar">
                                    <?php if (!empty($profilePicture) && file_exists(__DIR__ . '/' . $profilePicture)): ?>
                                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?php echo $staffInitial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="info">
                                    <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                    <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron" style="color: #64748b;"></i>
                            </div>

                            <div class="profile-dropdown" id="profileDropdown">
                                <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                                <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                                <div class="dropdown-divider"></div>
                                <a href="#" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </header>
            </div>

            <main class="content">
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                    <span class="sep"><i class="fa-solid fa-angle-right"></i></span>
                    <strong>Payments</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-money-check-dollar"></i> Payments</h1>
                        <p>Verify and manage owner payments for rental registration.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?>
                    </div>
                </div>

                <!-- STATS -->
                <div class="stats-grid">
                    <div class="stat-card bg-amber" onclick="filterByStatus('Pending')">
                        <div>
                            <span class="label">Pending Verification</span>
                            <div class="value"><?php echo number_format($pendingCount); ?></div>
                            <span class="sub"><?php echo $overduePending > 0 ? number_format($overduePending) . ' waiting 3+ days' : 'Queue is up to date'; ?></span>
                        </div>
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="stat-card bg-green" onclick="filterByStatus('Verified')">
                        <div>
                            <span class="label">Verified Payments</span>
                            <div class="value"><?php echo number_format($verifiedCount); ?></div>
                            <span class="sub"><?php echo number_format($rejectedCount); ?> rejected</span>
                        </div>
                        <i class="fa-solid fa-check-circle"></i>
                    </div>
                    <div class="stat-card bg-purple" onclick="filterByStatus('all')">
                        <div>
                            <span class="label">Total Payments</span>
                            <div class="value"><?php echo number_format($totalCount); ?></div>
                            <span class="sub">All records</span>
                        </div>
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card bg-green" onclick="window.open('payments.php?report=daily&date=<?php echo date('Y-m-d'); ?>', '_blank')" title="Open today's collection report">
                        <div>
                            <span class="label">Collected Today</span>
                            <div class="value"><?php echo formatCurrency($todayAmount); ?></div>
                            <span class="sub"><?php echo number_format($todayCount); ?> verified payment(s)</span>
                        </div>
                        <i class="fa-solid fa-peso-sign"></i>
                    </div>
                    <div class="stat-card bg-purple">
                        <div>
                            <span class="label">Collected This Month</span>
                            <div class="value"><?php echo formatCurrency($monthAmount); ?></div>
                            <span class="sub"><?php echo date('F Y'); ?></span>
                        </div>
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="stat-card bg-amber" onclick="filterByStatus('Pending')">
                        <div>
                            <span class="label">Amount Pending Verification</span>
                            <div class="value"><?php echo formatCurrency($pendingAmount); ?></div>
                            <span class="sub">Not yet counted as collected</span>
                        </div>
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>

                <div class="charts-row">
                    <div class="chart-card">
                        <h4><i class="fa-solid fa-chart-column" style="color:var(--primary-red);"></i> Verified Collections (Last 6 Months)</h4>
                        <div class="chart-box">
                            <?php if (!$hasMonthlyData): ?><div class="chart-empty"><i class="fa-solid fa-chart-column"></i>No verified payments yet</div><?php endif; ?>
                            <canvas id="staffMonthlyChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h4><i class="fa-solid fa-chart-pie" style="color:var(--primary-red);"></i> By Payment Method</h4>
                        <div class="chart-box">
                            <?php if (!$hasMethodData): ?><div class="chart-empty"><i class="fa-solid fa-chart-pie"></i>No verified payments yet</div><?php endif; ?>
                            <canvas id="staffMethodChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="filter-card">
                    <form method="GET" action="payments.php" class="filter-left">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search ref no., business, owner..." value="<?php echo h($search); ?>" maxlength="80">
                        </div>
                        <select name="status">
                            <option value="all">All Status</option>
                            <option value="Pending" <?php echo $filterStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Verified" <?php echo $filterStatus === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="Rejected" <?php echo $filterStatus === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                        <select name="method">
                            <option value="all">All Methods</option>
                            <option value="cash" <?php echo $fMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="ewallet" <?php echo $fMethod === 'ewallet' ? 'selected' : ''; ?>>E-Wallet</option>
                            <option value="bank" <?php echo $fMethod === 'bank' ? 'selected' : ''; ?>>Bank</option>
                        </select>
                        <?php if ($hasPurposeColumn): ?>
                        <select name="purpose">
                            <option value="all">All Purposes</option>
                            <?php foreach ($allowedPurposes as $ap): ?>
                                <option value="<?php echo h($ap); ?>" <?php echo $fPurpose === $ap ? 'selected' : ''; ?>><?php echo h($ap); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <input type="date" name="from" value="<?php echo h($fFrom); ?>" title="Payment date from">
                        <input type="date" name="to" value="<?php echo h($fTo); ?>" title="Payment date to">
                        <select name="sort" title="Sort">
                            <option value="queue" <?php echo $fSort === 'queue' ? 'selected' : ''; ?>>Pending first (oldest)</option>
                            <option value="newest" <?php echo $fSort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                            <option value="oldest" <?php echo $fSort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                            <option value="amount_desc" <?php echo $fSort === 'amount_desc' ? 'selected' : ''; ?>>Highest amount</option>
                            <option value="amount_asc" <?php echo $fSort === 'amount_asc' ? 'selected' : ''; ?>>Lowest amount</option>
                        </select>
                        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                        <?php if ($hasActiveFilters): ?>
                            <a href="payments.php" class="btn-filter-clear">Clear</a>
                        <?php endif; ?>
                    </form>
                    <div class="filter-tools">
                        <a class="tool-btn" href="<?php echo h($pageUrl(['export' => 'csv'])); ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                        <form method="GET" action="payments.php" target="_blank">
                            <input type="hidden" name="report" value="daily">
                            <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" title="Report date">
                            <button type="submit" class="tool-btn"><i class="fa-solid fa-print"></i> Daily Collection Report</button>
                        </form>
                    </div>
                </div>

                <!-- PANEL -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-list"></i> Payment Records
                        </div>
                        <div class="panel-meta">Showing <strong><?php echo number_format($showFrom); ?>&ndash;<?php echo number_format($showTo); ?></strong> of <strong><?php echo number_format($totalRows); ?></strong> record<?php echo $totalRows === 1 ? '' : 's'; ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>OR / Ref Number</th>
                                    <th>Payment Purpose</th>
                                    <th>Owner</th>
                                    <th>Business</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($payments)): ?>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td data-label="OR / Ref Number">
                                                <?php
                                                    $refDisplay = '';
                                                    foreach (['or_number', 'reference_no', 'reference_number'] as $rc) {
                                                        if (!empty($p[$rc])) { $refDisplay = (string)$p[$rc]; break; }
                                                    }
                                                    if ($refDisplay === '') $refDisplay = 'N/A';
                                                    $isPendingRow = strtolower((string)($p['status'] ?? '')) === 'pending';
                                                    $ageH = (isset($p['age_hours']) && $p['age_hours'] !== null) ? (int)$p['age_hours'] : null;
                                                ?>
                                                <strong class="tracking"><?php echo h($refDisplay); ?></strong>
                                                <?php if ((int)($p['dup_count'] ?? 0) > 0): ?>
                                                    <br><span class="flag flag-dup" title="Another active payment uses this same reference number"><i class="fa-solid fa-triangle-exclamation"></i> Duplicate ref</span>
                                                <?php endif; ?>
                                                <?php if ($isPendingRow && $ageH !== null): ?>
                                                    <br><span class="flag flag-age <?php echo $ageH >= 72 ? 'old' : ($ageH >= 24 ? 'warn' : ''); ?>">
                                                        <i class="fa-regular fa-clock"></i> <?php echo $ageH >= 24 ? floor($ageH / 24) . 'd' : max(0, $ageH) . 'h'; ?> waiting
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Payment Purpose">
                                                <?php 
                                                    $rawPurpose = trim($p['payment_purpose'] ?? $p['fee_type'] ?? '');
                                                    $remarksText = strtolower($p['remarks'] ?? '');

                                                    $purpose = '';
                                                    $badgeClass = 'badge-purpose-new';
                                                    $icon = 'fa-file-invoice';

                                                    if (!empty($rawPurpose)) {
                                                        $purpose = $rawPurpose;
                                                    } elseif (strpos($remarksText, 'inspection') !== false) {
                                                        $purpose = 'Inspection Fee';
                                                    } elseif (strpos($remarksText, 'penalty') !== false || strpos($remarksText, 'late') !== false) {
                                                        $purpose = 'Penalty Fee';
                                                    } elseif (!empty($p['renewal_id']) || !empty($p['linked_renewal_id']) || strpos($remarksText, 'renewal') !== false) {
                                                        $purpose = 'Permit Renewal';
                                                    } else {
                                                        $purpose = 'New Registration';
                                                    }

                                                    $pLower = strtolower($purpose);
                                                    if (strpos($pLower, 'renewal') !== false) {
                                                        $badgeClass = 'badge-purpose-renewal';
                                                        $icon = 'fa-arrows-rotate';
                                                    } elseif (strpos($pLower, 'inspection') !== false) {
                                                        $badgeClass = 'badge-purpose-inspection';
                                                        $icon = 'fa-clipboard-check';
                                                    } elseif (strpos($pLower, 'penalty') !== false || strpos($pLower, 'fine') !== false || strpos($pLower, 'late') !== false) {
                                                        $badgeClass = 'badge-purpose-penalty';
                                                        $icon = 'fa-triangle-exclamation';
                                                    } else {
                                                        $badgeClass = 'badge-purpose-new';
                                                        $icon = 'fa-plus-circle';
                                                    }

                                                    echo '<span class="' . $badgeClass . '"><i class="fa-solid ' . $icon . ' me-1"></i> ' . h($purpose) . '</span>';

                                                    if (!empty($p['renewal_doc_type'])) {
                                                        echo '<br><small style="color:var(--primary-red); font-size:10.5px;"><i class="fa-solid fa-file-contract me-1"></i> Doc: ' . h($p['renewal_doc_type']) . '</small>';
                                                    }
                                                ?>
                                            </td>
                                            <td data-label="Owner">
                                                <?php echo h($p['owner_name'] ?? '—'); ?>
                                                <br><small style="color:#64748b;"><?php echo h($p['owner_email'] ?? ''); ?></small>
                                            </td>
                                            <td data-label="Business">
                                                <?php echo h($p['business_name'] ?? '—'); ?>
                                                <br><small style="color:#64748b;"><?php echo h($p['tracking_no'] ?? ''); ?></small>
                                            </td>
                                            <!-- ✅ CHANGED TO BLACK -->
                                            <td data-label="Amount" style="font-weight:600;color:#000000;">
                                                <?php echo formatCurrency($p['amount_paid'] ?? 0); ?>
                                            </td>
                                            <td data-label="Date"><?php echo date('M d, Y', strtotime($p['payment_date'] ?? 'now')); ?></td>
                                            <td data-label="Method">
                                                <?php
                                                $method = strtolower(trim($p['payment_method'] ?? ''));
                                                $methodLabels = [
                                                    'cash'          => ['badge-success', 'fa-money-bill', 'Cash'],
                                                    'gcash'         => ['badge-gcash', 'fa-mobile-screen-button', 'GCash'],
                                                    'maya'          => ['badge-maya', 'fa-wallet', 'Maya'],
                                                    'bank transfer' => ['badge-bank', 'fa-building-columns', 'Bank Transfer'],
                                                    'bank'          => ['badge-bank', 'fa-building-columns', 'Bank'],
                                                    'card'          => ['badge-card', 'fa-credit-card', 'Card'],
                                                    'check'         => ['badge-check', 'fa-money-check-dollar', 'Check'],
                                                    'online'        => ['badge-bank', 'fa-wifi', 'Online'],
                                                ];
                                                if (isset($methodLabels[$method])) {
                                                    [$cls, $icon, $label] = $methodLabels[$method];
                                                    echo '<span class="badge ' . $cls . '"><i class="fa-solid ' . $icon . '"></i> ' . h($label) . '</span>';
                                                } else {
                                                    echo '<span class="badge badge-secondary">' . h($p['payment_method'] ?? '—') . '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td data-label="Status">
                                                <?php
                                                $stLow = strtolower((string)($p['status'] ?? ''));
                                                $verifiedAtTxt = ($hasVerifiedAt && !empty($p['verified_at'])) ? date('M d, Y h:i A', strtotime($p['verified_at'])) : '';
                                                $verifierTxt = (string)($p['verifier_name'] ?? '');
                                                $rejectReasonTxt = '';
                                                if ($hasRejectReason && !empty($p['reject_reason'])) {
                                                    $rejectReasonTxt = (string)$p['reject_reason'];
                                                } elseif (!empty($p['remarks']) && preg_match('/Rejected:\s*(.+)$/mi', (string)$p['remarks'], $rm)) {
                                                    $rejectReasonTxt = trim($rm[1]);
                                                }
                                                if (in_array($stLow, ['paid', 'verified', 'completed'], true)) {
                                                    echo '<span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> ' . h($p['status']) . '</span>';
                                                    if ($verifiedAtTxt !== '') echo '<span class="row-note">' . h($verifiedAtTxt) . ($verifierTxt !== '' ? ' &middot; by ' . h($verifierTxt) : '') . '</span>';
                                                } elseif ($stLow === 'pending') {
                                                    echo '<span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Pending</span>';
                                                } elseif (in_array($stLow, ['rejected', 'cancelled'], true)) {
                                                    echo '<span class="badge" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;"><i class="fa-solid fa-ban"></i> Rejected</span>';
                                                    if ($rejectReasonTxt !== '') echo '<span class="row-note bad">' . h($rejectReasonTxt) . '</span>';
                                                } else {
                                                    echo '<span class="badge badge-secondary">' . h($p['status'] ?? '') . '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td data-label="Action">
                                                <?php
                                                    /* ✅ FIXED: Proof URL Support (Supabase vs Local) */
                                                    $proofRaw = ($proofColumnName !== null && !empty($p[$proofColumnName])) ? (string)$p[$proofColumnName] : '';
                                                    if ($proofRaw === '' && !empty($p['remarks']) && preg_match('/uploads\/payment_proofs\/[^\s]+/', (string)$p['remarks'], $matches)) {
                                                        $proofRaw = $matches[0];
                                                    }

                                                    if (filter_var($proofRaw, FILTER_VALIDATE_URL)) {
                                                        // Supabase URL
                                                        $proofUrl = $proofRaw;
                                                    } elseif (preg_match('#^[A-Za-z0-9_./\-]+$#', $proofRaw) && strpos($proofRaw, '..') === false) {
                                                        // Local fallback
                                                        $proofUrl = '../' . ltrim($proofRaw, '/');
                                                    } else {
                                                        $proofUrl = '';
                                                    }

                                                    $detailPayload = [
                                                        'id'         => (int)$p['id'],
                                                        'orNumber'   => $refDisplay,
                                                        'purpose'    => $purpose,
                                                        'docType'    => $p['renewal_doc_type'] ?? '',
                                                        'owner'      => $p['owner_name'] ?? '—',
                                                        'business'   => $p['business_name'] ?? '—',
                                                        'amount'     => formatCurrency($p['amount_paid'] ?? 0),
                                                        'date'       => date('M d, Y', strtotime($p['payment_date'] ?? 'now')),
                                                        'method'     => $p['payment_method'] ?? '—',
                                                        'reference'  => '',
                                                        'location'   => $p['collection_location'] ?? '',
                                                        'remarks'    => $p['remarks'] ?? '',
                                                        'status'     => $p['status'] ?? '',
                                                        'proofUrl'   => $proofUrl,
                                                        'dup'        => (int)($p['dup_count'] ?? 0),
                                                        'verifiedAt' => $verifiedAtTxt,
                                                        'verifiedBy' => $verifierTxt,
                                                        'rejectReason' => $rejectReasonTxt,
                                                    ];
                                                ?>
                                                <div class="action-cell">
                                                    <button class="btn-view" data-payment="<?php echo h(json_encode($detailPayload)); ?>" onclick="showPaymentDetails(JSON.parse(this.dataset.payment))">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </button>
                                                    <?php if ($isPendingRow): ?>
                                                        <button class="btn-verify" onclick="verifyFromBtn(this)"
                                                                data-id="<?php echo (int)$p['id']; ?>"
                                                                data-ref="<?php echo h($refDisplay); ?>"
                                                                data-business="<?php echo h($p['business_name'] ?? ''); ?>"
                                                                data-amount="<?php echo h(formatCurrency($p['amount_paid'] ?? 0)); ?>"
                                                                data-dup="<?php echo (int)($p['dup_count'] ?? 0); ?>">
                                                            <i class="fa-solid fa-check-circle"></i> Verify
                                                        </button>
                                                        <button class="btn-reject" onclick="rejectFromBtn(this)"
                                                                data-id="<?php echo (int)$p['id']; ?>"
                                                                data-ref="<?php echo h($refDisplay); ?>"
                                                                data-dup="<?php echo (int)($p['dup_count'] ?? 0); ?>">
                                                            <i class="fa-solid fa-xmark"></i> Reject
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($p['linked_renewal_id'])): ?>
                                                        <a class="btn-view" href="renewals_approval.php" style="text-decoration:none;" title="Linked renewal request #<?php echo (int)$p['linked_renewal_id']; ?>"><i class="fa-solid fa-arrows-rotate"></i> Renewal</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class="fa-solid fa-receipt"></i>
                                                <h4>No payment records found</h4>
                                                <p>Wala pang pumapasok na rental o payment records sa kasalukuyan.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalRows > 0): ?>
                <div class="pager">
                    <span class="pager-info">Showing <?php echo number_format($showFrom); ?>&ndash;<?php echo number_format($showTo); ?> of <?php echo number_format($totalRows); ?> payment(s)</span>
                    <?php if ($totalPages > 1): ?>
                    <div class="pager-links">
                        <?php if ($page > 1): ?><a href="<?php echo h($pageUrl(['page' => $page - 1])); ?>">&laquo; Prev</a><?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a class="<?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo h($pageUrl(['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="<?php echo h($pageUrl(['page' => $page + 1])); ?>">Next &raquo;</a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>
            </main>
        </div>
    </div>

    <!-- PAYMENT DETAILS MODAL -->
    <div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-labelledby="paymentDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentDetailsModalLabel" style="font-size: 14.5px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px;">
                        <i class="fa-solid fa-receipt" style="color: var(--primary-red);"></i> Payment Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="detail-row"><span class="detail-label">OR / Ref Number</span><span class="detail-value" style="color:#000000;" id="pd-orNumber"></span></div>
                            <div class="detail-row"><span class="detail-label">Payment Purpose</span><span class="detail-value" style="color:#000000;" id="pd-purpose"></span></div>
                            <div class="detail-row" id="pd-docType-row" style="display:none;"><span class="detail-label">Document Type</span><span class="detail-value" style="color:#000000;" id="pd-docType"></span></div>
                            <div class="detail-row"><span class="detail-label">Owner</span><span class="detail-value" id="pd-owner"></span></div>
                            <div class="detail-row"><span class="detail-label">Business</span><span class="detail-value" id="pd-business"></span></div>
                            <div class="detail-row"><span class="detail-label">Amount</span><span class="detail-value" style="color:#000000;" id="pd-amount"></span></div>
                            <div class="detail-row"><span class="detail-label">Payment Date</span><span class="detail-value" id="pd-date"></span></div>
                            <div class="detail-row"><span class="detail-label">Method</span><span class="detail-value" id="pd-method"></span></div>
                            <div class="detail-row" id="pd-reference-row" style="display:none;"><span class="detail-label">Reference No.</span><span class="detail-value" id="pd-reference"></span></div>
                            <div class="detail-row" id="pd-location-row" style="display:none;"><span class="detail-label">Location</span><span class="detail-value" id="pd-location"></span></div>
                            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value" id="pd-status"></span></div>
                            <div class="detail-row" id="pd-audit-row" style="display:none;"><span class="detail-label">Verified</span><span class="detail-value" id="pd-audit" style="font-weight:500;"></span></div>
                            <div class="detail-row" id="pd-reject-row" style="display:none; align-items:flex-start;"><span class="detail-label">Reject Reason</span><span class="detail-value" id="pd-reject" style="text-align:right; font-weight:500; color:#b91c1c; max-width:60%;"></span></div>
                            <div class="detail-row" id="pd-dup-row" style="display:none;"><span class="detail-label">Warning</span><span class="detail-value" style="color:#b91c1c;">Reference number is used by another active payment</span></div>
                            <div class="detail-row" id="pd-remarks-row" style="display:none; align-items:flex-start;">
                                <span class="detail-label">Remarks</span>
                                <span class="detail-value" id="pd-remarks" style="text-align:right; font-weight:400; color:#475569; max-width:60%;"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <p style="font-size:12px; color:#64748b; margin-bottom:8px; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">Proof of Payment</p>
                            <div id="pd-proof-container"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" id="pd-action-footer" style="display:none;">
                    <button type="button" class="btn-reject" id="pd-reject-btn"><i class="fa-solid fa-xmark"></i> Reject</button>
                    <button type="button" class="btn-verify" id="pd-verify-btn"><i class="fa-solid fa-check-circle"></i> Verify Payment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- IMAGE LIGHTBOX ZOOM MODAL -->
    <div class="modal fade" id="imageZoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content overflow-hidden">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 14px; color: var(--primary-red);"><i class="fa-solid fa-magnifying-glass-plus"></i> Proof of Payment Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="zoomedImage" src="" alt="Zoomed Proof" style="max-width: 100%; max-height: 80vh; border-radius: 12px; object-fit: contain; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH MODAL -->
    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="searchModalLabel" style="font-size: 14.5px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px;">
                        <i class="fa-solid fa-magnifying-glass" style="color: var(--primary-red);"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 16px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="width: 100%; padding: 14px 16px 14px 48px; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 14px; outline: none;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto; padding-right: 4px;">
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>
                            Type at least 2 characters to begin searching records...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

        /* ============================================
           NAV MENU
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

        menuToggle.addEventListener('click', openDrawer);
        sidebarOverlay.addEventListener('click', closeDrawer);
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

        /* CLOCK */
        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const clockEl = document.getElementById('clockTime');
            if (clockEl) clockEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        /* NOTIFICATIONS + PROFILE */
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');

        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown?.classList.remove('show');
            notifDropdown.classList.toggle('show');
            if (notifDropdown.classList.contains('show')) {
                fetch('payments.php?ajax_action=read_notif', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                    body: ''
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            const badge = notifBtn.querySelector('.badge');
                            if (badge) badge.style.display = 'none';
                        }
                    });
            }
        });

        profileToggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown?.classList.remove('show');
            profileDropdown.classList.toggle('show');
        });

        window.addEventListener('click', () => {
            notifDropdown?.classList.remove('show');
            profileDropdown?.classList.remove('show');
        });

        /* SEARCH MODAL */
        const searchModalElement = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        if (searchModalElement) {
            searchModalElement.addEventListener('shown.bs.modal', function () {
                modalSearchInput.focus();
            });

            let searchDebounce;
            modalSearchInput.addEventListener('input', function() {
                clearTimeout(searchDebounce);
                let query = this.value.trim();
                if (query.length < 2) {
                    modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>Type at least 2 characters to begin searching records...</div>`;
                    return;
                }
                searchDebounce = setTimeout(() => {
                    fetch('payments.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            if (html.trim() !== '') modalSearchResults.innerHTML = html;
                            else modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* HELPERS */
        function filterByStatus(status) { window.location.href = 'payments.php?status=' + status; }

        function showPaymentDetails(p) {
            document.getElementById('pd-orNumber').textContent = p.orNumber || 'N/A';
            document.getElementById('pd-purpose').textContent = p.purpose || 'New Registration';

            const docTypeRow = document.getElementById('pd-docType-row');
            if (p.docType && p.docType.trim() !== '') {
                document.getElementById('pd-docType').textContent = p.docType;
                docTypeRow.style.display = 'flex';
            } else {
                docTypeRow.style.display = 'none';
            }

            document.getElementById('pd-owner').textContent = p.owner || '—';
            document.getElementById('pd-business').textContent = p.business || '—';
            document.getElementById('pd-amount').textContent = p.amount || '—';
            document.getElementById('pd-date').textContent = p.date || '—';
            document.getElementById('pd-method').textContent = p.method || '—';
            document.getElementById('pd-status').textContent = p.status || '—';

            const refRow = document.getElementById('pd-reference-row');
            if (p.reference && p.reference.trim() !== '') {
                document.getElementById('pd-reference').textContent = p.reference;
                refRow.style.display = 'flex';
            } else {
                refRow.style.display = 'none';
            }

            const locRow = document.getElementById('pd-location-row');
            if (p.location && p.location.trim() !== '') {
                document.getElementById('pd-location').textContent = p.location;
                locRow.style.display = 'flex';
            } else {
                locRow.style.display = 'none';
            }

            const remarksRow = document.getElementById('pd-remarks-row');
            let cleanRemarks = (p.remarks || '').replace(/Proof of payment saved at:\s*uploads\/payment_proofs\/[^\s]+/gi, '').trim();
            cleanRemarks = cleanRemarks.replace(/uploads\/payment_proofs\/[^\s]+/gi, '').trim();

            if (cleanRemarks !== '') {
                document.getElementById('pd-remarks').textContent = cleanRemarks;
                remarksRow.style.display = 'flex';
            } else {
                remarksRow.style.display = 'none';
            }

            const auditRow = document.getElementById('pd-audit-row');
            const rejectRow = document.getElementById('pd-reject-row');
            const dupRow = document.getElementById('pd-dup-row');
            if (auditRow) {
                if (p.verifiedAt) { document.getElementById('pd-audit').textContent = p.verifiedAt + (p.verifiedBy ? ' by ' + p.verifiedBy : ''); auditRow.style.display = 'flex'; }
                else auditRow.style.display = 'none';
            }
            if (rejectRow) {
                if (p.rejectReason) { document.getElementById('pd-reject').textContent = p.rejectReason; rejectRow.style.display = 'flex'; }
                else rejectRow.style.display = 'none';
            }
            if (dupRow) dupRow.style.display = (p.dup && Number(p.dup) > 0) ? 'flex' : 'none';

            /* ✅ FIXED: Proof Viewer handles Supabase URLs & Local Files */
            const proofContainer = document.getElementById('pd-proof-container');
            proofContainer.textContent = '';
            const rawProof = (p.proofUrl || '').trim();
            
            if (rawProof !== '') {
                const box = document.createElement('div');
                box.className = 'proof-preview-box';
                const isPdf = rawProof.toLowerCase().endsWith('.pdf');

                if (isPdf) {
                    box.innerHTML = '<i class="fa-solid fa-file-pdf" style="font-size:32px;color:#ef4444;display:block;margin-bottom:8px;"></i>';
                    const a = document.createElement('a');
                    a.className = 'proof-link'; a.href = rawProof; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Open PDF proof';
                    box.appendChild(a);
                } else {
                    const img = document.createElement('img');
                    img.src = rawProof; img.alt = 'Proof of payment';
                    img.addEventListener('click', () => openZoomModal(rawProof));
                    const hint = document.createElement('span');
                    hint.className = 'proof-hint';
                    hint.innerHTML = '<i class="fa-solid fa-magnifying-glass-plus"></i> Click image to zoom popup';
                    box.appendChild(img); box.appendChild(hint);
                }
                proofContainer.appendChild(box);
            } else {
                proofContainer.innerHTML = '<div class="proof-empty"><i class="fa-solid fa-image" style="font-size:22px;display:block;margin-bottom:6px;opacity:0.5;"></i>No proof of payment was attached for this transaction.</div>';
            }

            const footer = document.getElementById('pd-action-footer');
            const verifyBtn = document.getElementById('pd-verify-btn');
            const rejectBtn = document.getElementById('pd-reject-btn');
            if ((p.status || '').toLowerCase() === 'pending') {
                footer.style.display = 'flex';
                verifyBtn.onclick = () => {
                    bootstrap.Modal.getInstance(document.getElementById('paymentDetailsModal'))?.hide();
                    verifyPayment(p.id, p.orNumber, p.business, p.amount, p.dup);
                };
                rejectBtn.onclick = () => {
                    bootstrap.Modal.getInstance(document.getElementById('paymentDetailsModal'))?.hide();
                    rejectPayment(p.id, p.orNumber, p.dup);
                };
            } else {
                footer.style.display = 'none';
            }

            new bootstrap.Modal(document.getElementById('paymentDetailsModal')).show();
        }

        function openZoomModal(imgUrl) {
            document.getElementById('zoomedImage').src = imgUrl;
            const paymentModalEl = document.getElementById('paymentDetailsModal');
            const paymentModal = bootstrap.Modal.getInstance(paymentModalEl);
            if (paymentModal) {
                paymentModal.hide();
            }
            const zoomModal = new bootstrap.Modal(document.getElementById('imageZoomModal'));
            zoomModal.show();

            document.getElementById('imageZoomModal').addEventListener('hidden.bs.modal', function () {
                if (paymentModal) {
                    paymentModal.show();
                }
            }, { once: true });
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text == null ? '' : text).replace(/[&<>"']/g, m => map[m]);
        }

        function verifyFromBtn(btn) {
            verifyPayment(btn.dataset.id, btn.dataset.ref, btn.dataset.business, btn.dataset.amount, btn.dataset.dup);
        }
        function rejectFromBtn(btn) {
            rejectPayment(btn.dataset.id, btn.dataset.ref, btn.dataset.dup);
        }

        /* Isang lugar para sa pagpapadala sa verify_payment.php (may loading at malinaw na error) */
        function sendPaymentAction(payload, successTitle, successText, redirectUrl) {
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => Swal.showLoading() });
            const body = new URLSearchParams(Object.assign({}, payload, { csrf_token: CSRF_TOKEN })).toString();
            fetch('verify_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                body: body
            })
            .then(res => res.json().catch(() => ({ success: false, message: 'Unexpected server response.' })))
            .then(data => {
                if (data.success) {
                    Swal.fire({ title: successTitle, text: successText, icon: 'success', confirmButtonColor: '#dc2626' })
                        .then(() => { window.location.href = redirectUrl; });
                } else {
                    Swal.fire({ title: 'Error!', text: data.message || 'The action could not be completed.', icon: 'error', confirmButtonColor: '#dc2626' })
                        .then(() => { window.location.reload(); });
                }
            })
            .catch(() => {
                Swal.fire({ title: 'Network error', text: 'Could not reach the server. Please try again.', icon: 'error', confirmButtonColor: '#dc2626' });
            });
        }

        function verifyPayment(id, orNumber, business, amount, dup) {
            const dupWarn = (Number(dup) > 0)
                ? '<div style="margin-top:10px;padding:8px 10px;border-radius:8px;background:#fee2e2;color:#b91c1c;font-size:12.5px;"><strong>Warning:</strong> another active payment uses this same reference number. Verify only if you have confirmed it is not a duplicate.</div>'
                : '';
            Swal.fire({
                title: 'Verify Payment',
                html: '<div style="text-align:left;color:#475569;font-size:14px;">'
                    + '<p><strong style="color:#0f172a;">Reference / OR:</strong> ' + escapeHtml(orNumber) + '</p>'
                    + '<p><strong style="color:#0f172a;">Business:</strong> ' + escapeHtml(business) + '</p>'
                    + '<p><strong style="color:#0f172a;">Amount:</strong> ' + escapeHtml(amount) + '</p>'
                    + '<p style="font-size:12px;color:#64748b;">Please confirm that the proof matches the amount and the reference number.</p>'
                    + dupWarn + '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Verify Payment'
            }).then((result) => {
                if (result.isConfirmed) {
                    sendPaymentAction({ payment_id: id, action: 'verify' }, 'Verified!', 'Payment verified successfully. The owner has been notified.', 'payments.php?status=Verified');
                }
            });
        }

        function rejectPayment(id, orNumber, dup) {
            Swal.fire({
                title: 'Reject Payment',
                html: '<div style="text-align:left;color:#475569;font-size:13.5px;">'
                    + '<p style="margin-bottom:10px;">Reject <strong>' + escapeHtml(orNumber) + '</strong>? The owner will see the reason below and can resubmit.</p>'
                    + '<label style="font-weight:700;font-size:12px;">Reason</label>'
                    + '<select id="rj-code" class="swal2-select" style="width:100%;margin:6px 0 12px;">'
                    + '<option value="">-- Select a reason --</option>'
                    + '<option value="blurred">Proof is blurred / unreadable</option>'
                    + '<option value="amount">Amount does not match</option>'
                    + '<option value="reference">Reference number does not match</option>'
                    + '<option value="duplicate"' + (Number(dup) > 0 ? ' selected' : '') + '>Duplicate / already used</option>'
                    + '<option value="channel">Not paid to an official LGU channel</option>'
                    + '<option value="other">Other (add a note)</option>'
                    + '</select>'
                    + '<label style="font-weight:700;font-size:12px;">Note (optional, shown to owner)</label>'
                    + '<textarea id="rj-note" class="swal2-textarea" maxlength="300" style="width:100%;margin:6px 0 0;height:70px;"></textarea>'
                    + '</div>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Reject Payment',
                preConfirm: () => {
                    const code = document.getElementById('rj-code').value;
                    const note = document.getElementById('rj-note').value.trim();
                    if (!code) { Swal.showValidationMessage('Please select a reason.'); return false; }
                    if (code === 'other' && !note) { Swal.showValidationMessage('Please add a note for "Other".'); return false; }
                    return { code: code, note: note };
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    sendPaymentAction({ payment_id: id, action: 'reject', reason_code: result.value.code, reason_note: result.value.note },
                        'Rejected', 'Payment rejected. The owner has been notified with your reason.', 'payments.php?status=Rejected');
                }
            });
        }

        /* CHARTS */
        const peso = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        if (window.Chart) {
            const mc = document.getElementById('staffMonthlyChart');
            if (mc) new Chart(mc, {
                type: 'bar',
                data: { labels: <?php echo json_encode($chartMonthLabels); ?>, datasets: [{ label: 'Verified collections', data: <?php echo json_encode($chartMonthValues); ?>, backgroundColor: 'rgba(220, 38, 38, 0.85)', hoverBackgroundColor: '#991b1b', borderRadius: 8, maxBarThickness: 42 }] },
                options: { responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + peso(c.parsed.y) } } },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: (v) => peso(v) } } } }
            });
            const pc = document.getElementById('staffMethodChart');
            if (pc) new Chart(pc, {
                type: 'doughnut',
                data: { labels: <?php echo json_encode($chartMethodLabels); ?>, datasets: [{ data: <?php echo json_encode($chartMethodValues); ?>, backgroundColor: ['#4338ca', '#dc2626', '#059669', '#f59e0b', '#94a3b8'], borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + peso(c.parsed) } } } }
            });
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Logout',
                text: 'Are you sure you want to end your session?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((result) => {
                if (result.isConfirmed) document.getElementById('logoutForm').submit();
            });
        }

        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });
    </script>
</body>
</html>