<?php
/* =========================================================
   INSPECTOR DASHBOARD — Modern Search Modal + Charts
   Menu labels updated: Main→Field Ops, Field Ops→Reports
   Removed dropdown section labels
   ADDED: Chart.js status doughnut + 6-month trend bar chart
   ========================================================= */
include "includes/auth.php";
include "../config/db.php";

date_default_timezone_set("Asia/Manila");

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

$inspectorId = (int) $_SESSION['user_id'];
$fullname    = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole   = htmlspecialchars($_SESSION['role'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$userInitial = strtoupper(substr($fullname, 0, 1));

/* ================= AJAX HANDLERS ================= */
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax_action'];

    /* ---- GLOBAL SEARCH ---- */
    if ($action === 'global_search') {
        $q = trim($_GET['q'] ?? '');
        $results = [];
        if (strlen($q) >= 1) {
            $searchTerm = '%' . $q . '%';

            // Search properties/rentals
            if (tableExists($conn, 'rentals')) {
                $nameCol = columnExists($conn, 'rentals', 'business_name') ? 'business_name' :
                          (columnExists($conn, 'rentals', 'rental_name') ? 'rental_name' :
                          (columnExists($conn, 'rentals', 'name') ? 'name' : 'id'));
                $ownerCol = columnExists($conn, 'rentals', 'owner_name') ? 'owner_name' : 'id';
                $trackCol = columnExists($conn, 'rentals', 'tracking_no') ? 'tracking_no' : 'id';
                $brgyCol  = columnExists($conn, 'rentals', 'barangay') ? 'barangay' : 'id';

                $whereClause = [];
                $params = [':q' => $searchTerm];
                if ($nameCol !== 'id')  $whereClause[] = "$nameCol ILIKE :q";
                if ($ownerCol !== 'id') $whereClause[] = "$ownerCol ILIKE :q";
                if ($trackCol !== 'id') $whereClause[] = "$trackCol ILIKE :q";
                $whereClause[] = "CAST(id AS TEXT) ILIKE :q";
                $whereSql = implode(' OR ', $whereClause);
                $deletedSql = columnExists($conn, 'rentals', 'is_deleted') ? "WHERE is_deleted = FALSE AND ($whereSql)" : "WHERE ($whereSql)";

                $stmt = $conn->prepare("SELECT * FROM rentals $deletedSql LIMIT 5");
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $titleVal = $row[$nameCol] ?? ('Property #' . $row['id']);
                    $trackVal = $row[$trackCol] ?? 'N/A';
                    $ownerVal = $row[$ownerCol] ?? 'N/A';
                    $brgyVal  = $row[$brgyCol] ?? '';
                    $results[] = [
                        'type'  => 'Property',
                        'icon'  => 'fa-building',
                        'title' => $titleVal,
                        'sub'   => 'Owner: ' . $ownerVal . ($brgyVal ? ' · ' . $brgyVal : '') . ' · Track: ' . $trackVal,
                        'link'  => 'inspections.php?search=' . urlencode($q)
                    ];
                }
            }

            // Search users (owners)
            if (tableExists($conn, 'users')) {
                $uNameCol = columnExists($conn, 'users', 'fullname') ? 'fullname' : 'username';
                try {
                    $stmt = $conn->prepare("
                        SELECT * FROM users 
                        WHERE ({$uNameCol} ILIKE :q OR email ILIKE :q)
                          AND role::text IN ('owner', 'inspector', 'staff')
                        LIMIT 3
                    ");
                    $stmt->execute([':q' => $searchTerm]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $results[] = [
                            'type'  => 'Owner',
                            'icon'  => 'fa-user',
                            'title' => $row[$uNameCol] ?? 'N/A',
                            'sub'   => $row['email'] ?? '',
                            'link'  => 'properties.php?search=' . urlencode($q)
                        ];
                    }
                } catch (PDOException $e) {}
            }
        }
        echo json_encode($results);
        exit;
    }

    /* ---- CHAT HANDLERS ---- */
    if (in_array($action, ['send_chat', 'clear_chat'], true)) {
        $sentToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'], (string) $sentToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'msg' => 'Invalid request token. Please refresh the page.']);
            exit;
        }
    }

    $hasChat = tableExists($conn, 'chat_messages');

    $resolvePartnerId = function() use ($conn, $inspectorId) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE role::text = 'staff' AND id <> :iid ORDER BY id ASC LIMIT 1");
        $stmt->execute([':iid' => $inspectorId]);
        $pid = (int) $stmt->fetchColumn();
        if ($pid > 0) return $pid;
        $stmt = $conn->prepare("SELECT id FROM users WHERE role::text = 'admin' AND id <> :iid ORDER BY id ASC LIMIT 1");
        $stmt->execute([':iid' => $inspectorId]);
        $pid = (int) $stmt->fetchColumn();
        if ($pid > 0) return $pid;
        $stmt = $conn->prepare("SELECT id FROM users WHERE role::text IN ('staff', 'admin') AND id <> :iid ORDER BY id ASC LIMIT 1");
        $stmt->execute([':iid' => $inspectorId]);
        return (int) $stmt->fetchColumn();
    };

    if ($action === 'send_chat') {
        if (!$hasChat) { echo json_encode(['status' => 'error', 'msg' => 'Chat is not available.']); exit; }
        $receiverId = $resolvePartnerId();
        if ($receiverId <= 0) { echo json_encode(['status' => 'error', 'msg' => 'No staff account available.']); exit; }
        $message = trim($_POST['message'] ?? '');
        $attachmentPath = null;
        $attachmentRejected = false;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['attachment'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $okExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            $valid = $file['error'] === UPLOAD_ERR_OK && in_array($ext, $okExt, true) && $file['size'] > 0 && $file['size'] <= 5 * 1024 * 1024;
            if ($valid && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) $valid = @getimagesize($file['tmp_name']) !== false;
            elseif ($valid && $ext === 'pdf') $valid = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) === 'application/pdf';
            if ($valid) {
                $dir = __DIR__ . '/../uploads/chat/';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $newName = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $newName)) $attachmentPath = 'uploads/chat/' . $newName;
                else $attachmentRejected = true;
            } else $attachmentRejected = true;
        }
        if ($message === '' && $attachmentPath === null) {
            echo json_encode(['status' => 'error', 'msg' => $attachmentRejected
                ? 'Attachment rejected. Use JPG, PNG, GIF, PDF or DOC/DOCX up to 5MB.'
                : 'Message is empty.']);
            exit;
        }
        try {
            $insertStmt = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, attachment) VALUES (:sid, :rid, :msg, :att)");
            $insertStmt->execute([':sid' => $inspectorId, ':rid' => $receiverId, ':msg' => $message, ':att' => $attachmentPath]);
            echo json_encode(['status' => 'success', 'receiver_id' => $receiverId]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Could not send the message.']);
        }
        exit;
    }

    if ($action === 'fetch_chat') {
        $messages = [];
        if ($hasChat) {
            $partnerId = $resolvePartnerId();
            if ($partnerId > 0) {
                $stmt = $conn->prepare("
                    SELECT sender_id, receiver_id, message, attachment, created_at
                    FROM chat_messages
                    WHERE (sender_id = :uid AND receiver_id = :partner)
                       OR (sender_id = :partner AND receiver_id = :uid)
                    ORDER BY created_at ASC LIMIT 50
                ");
                $stmt->execute([':uid' => $inspectorId, ':partner' => $partnerId]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $messages[] = [
                        'is_outgoing' => ((int) $row['sender_id'] === $inspectorId),
                        'message'     => $row['message'],
                        'attachment'  => !empty($row['attachment']) ? '../' . $row['attachment'] : null,
                        'time'        => date('h:i A', strtotime($row['created_at'] ?? 'now'))
                    ];
                }
            }
        }
        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit;
    }

    if ($action === 'clear_chat') {
        if (!$hasChat) { echo json_encode(['status' => 'error', 'msg' => 'Chat is not available.']); exit; }
        $partnerId = $resolvePartnerId();
        if ($partnerId > 0) {
            $delStmt = $conn->prepare("
                DELETE FROM chat_messages 
                WHERE (sender_id = :uid AND receiver_id = :partner)
                   OR (sender_id = :partner AND receiver_id = :uid)
            ");
            $delStmt->execute([':uid' => $inspectorId, ':partner' => $partnerId]);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Unknown action.']);
    exit;
}

/* ================= PAGE DATA ================= */
$hour = (int) date('G');
if ($hour < 12) { $greeting = 'Good morning'; }
elseif ($hour < 18) { $greeting = 'Good afternoon'; }
else { $greeting = 'Good evening'; }
$todayLabel = date('l, F j, Y');
$todayDate  = date('Y-m-d');

function getPermitStatus($expiryDate) {
    if (empty($expiryDate)) return ['label' => 'No Permit', 'class' => 'secondary'];
    $today = time();
    $expiry = strtotime($expiryDate);
    if ($expiry < $today) return ['label' => 'Expired', 'class' => 'danger'];
    elseif (($expiry - $today) < (30 * 86400)) return ['label' => 'Expiring Soon', 'class' => 'warning'];
    return ['label' => 'Active', 'class' => 'success'];
}

$hasInspectionData = tableExists($conn, 'inspections') && tableExists($conn, 'rentals');

$statusCounts = [];
if ($hasInspectionData) {
    $stmt = $conn->prepare("
        SELECT i.inspection_status::text AS status, COUNT(*) AS c
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        GROUP BY i.inspection_status::text
    ");
    $stmt->execute([':iid' => $inspectorId]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[strtolower(trim((string) $r['status']))] = (int) $r['c'];
    }
}
$cnt = fn($k) => $statusCounts[$k] ?? 0;

$totalAssigned  = array_sum($statusCounts);
$totalCompleted = $cnt('completed') + $cnt('passed');
$totalScheduled = $cnt('scheduled');
$totalOngoing   = $cnt('ongoing');
$totalFailed    = $cnt('failed');
$completionRate = $totalAssigned > 0 ? round(($totalCompleted / $totalAssigned) * 100) : 0;

$unreadInspectionsCount = 0;
if ($hasInspectionData) {
    try {
        $chkCol = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'inspections' AND column_name = 'is_read')");
        $chkCol->execute();
        if ($chkCol->fetchColumn()) {
            $stmtUnread = $conn->prepare("
                SELECT COUNT(*) FROM inspections i
                INNER JOIN rentals r ON i.rental_id = r.id
                WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
                  AND (i.is_read = FALSE OR i.is_read IS NULL)
            ");
            $stmtUnread->execute([':iid' => $inspectorId]);
            $unreadInspectionsCount = (int) $stmtUnread->fetchColumn();
        }
    } catch (PDOException $e) { $unreadInspectionsCount = 0; }
}

$upcomingInspections = [];
if ($hasInspectionData) {
    $stmt = $conn->prepare("
        SELECT i.id, r.rental_name, r.barangay, r.permit_expiry,
               i.inspection_date, i.inspection_time, i.inspection_status
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
          AND i.inspection_status::text IN ('Scheduled', 'Ongoing')
        ORDER BY i.inspection_date ASC NULLS LAST, i.inspection_time ASC NULLS LAST
        LIMIT 5
    ");
    $stmt->execute([':iid' => $inspectorId]);
    $upcomingInspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$recentActivities = [];
if ($hasInspectionData) {
    $stmt = $conn->prepare("
        SELECT i.inspection_date AS date, i.inspection_status AS status, r.rental_name AS title
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
          AND i.inspection_status::text <> 'Scheduled'
        ORDER BY i.inspection_date DESC NULLS LAST LIMIT 5
    ");
    $stmt->execute([':iid' => $inspectorId]);
    $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$notifications = [];
if ($hasInspectionData) {
    try {
        $stmt = $conn->prepare("
            SELECT
              COUNT(*) FILTER (WHERE i.inspection_status::text IN ('Scheduled','Ongoing')
                               AND i.inspection_date::date = CAST(:t1 AS DATE)) AS today_cnt,
              COUNT(*) FILTER (WHERE i.inspection_status::text = 'Scheduled'
                               AND i.inspection_date::date < CAST(:t2 AS DATE)) AS overdue_cnt,
              COUNT(*) FILTER (WHERE i.inspection_status::text = 'Scheduled'
                               AND i.created_at >= NOW() - INTERVAL '48 hours') AS new_cnt,
              COUNT(DISTINCT r.id) FILTER (WHERE i.inspection_status::text IN ('Scheduled','Ongoing')
                               AND r.permit_expiry IS NOT NULL
                               AND r.permit_expiry::date < CAST(:t3 AS DATE)) AS expired_cnt
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        ");
        $stmt->execute([':t1' => $todayDate, ':t2' => $todayDate, ':t3' => $todayDate, ':iid' => $inspectorId]);
        $n = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $plural = fn($c, $one, $many) => $c . ' ' . ($c == 1 ? $one : $many);

        if ((int) ($n['new_cnt'] ?? 0) > 0)
            $notifications[] = ['icon' => 'fa-clipboard-check', 'href' => 'inspections.php?status=Scheduled',
                'title' => $plural((int) $n['new_cnt'], 'new inspection', 'new inspections') . ' assigned', 'sub' => 'Within the last 48 hours'];
        if ((int) ($n['today_cnt'] ?? 0) > 0)
            $notifications[] = ['icon' => 'fa-calendar-day', 'href' => 'inspections.php',
                'title' => $plural((int) $n['today_cnt'], 'inspection', 'inspections') . ' scheduled today', 'sub' => 'Check your field visits'];
        if ((int) ($n['overdue_cnt'] ?? 0) > 0)
            $notifications[] = ['icon' => 'fa-clock', 'href' => 'inspections.php?status=Scheduled',
                'title' => $plural((int) $n['overdue_cnt'], 'overdue inspection', 'overdue inspections'), 'sub' => 'Past the scheduled date'];
        if ((int) ($n['expired_cnt'] ?? 0) > 0)
            $notifications[] = ['icon' => 'fa-id-card', 'href' => 'properties.php',
                'title' => $plural((int) $n['expired_cnt'], 'property has', 'properties have') . ' an expired permit', 'sub' => 'On your pending inspections'];
    } catch (PDOException $e) { $notifications = []; }
}

$unreadNotifCount = count($notifications);

/* ================= CHART DATA (NEW) ================= */
$monthlyTrend = [];
if ($hasInspectionData) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                TO_CHAR(DATE_TRUNC('month', i.inspection_date), 'Mon YY') AS month_label,
                DATE_TRUNC('month', i.inspection_date) AS month_start,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE i.inspection_status::text IN ('Completed','Passed')) AS completed,
                COUNT(*) FILTER (WHERE i.inspection_status::text = 'Failed') AS failed
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            WHERE i.inspector_id = :iid 
              AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
              AND i.inspection_date IS NOT NULL
              AND i.inspection_date >= DATE_TRUNC('month', NOW()) - INTERVAL '5 months'
            GROUP BY month_label, month_start
            ORDER BY month_start ASC
        ");
        $stmt->execute([':iid' => $inspectorId]);
        $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $monthlyTrend = []; }
}

// Prepare status chart data (reuse existing $statusCounts)
$statusChartData = [
    'Completed' => $cnt('completed') + $cnt('passed'),
    'Scheduled' => $cnt('scheduled'),
    'Ongoing'   => $cnt('ongoing'),
    'Failed'    => $cnt('failed'),
    'Pending'   => $cnt('pending') + $cnt('pending_review'),
];
$statusChartTotal = array_sum($statusChartData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#ffffff">
    <title>Inspector Dashboard | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <!-- ✅ NEW: Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-card-alt: #f1f5f9;
            --topofficial-bg: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            --navbar-bg: #ffffff;
            --red-accent: #dc2626;
            --red-hover: #ef4444;
            --red-soft: #fee2e2;
            --gold-accent: #d97706;
            --teal-accent: #0891b2;
            --emerald-accent: #059669;
            --violet-accent: #7c3aed;
            --text-main: #0f172a;
            --text-sub: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --border-strong: #cbd5e1;
            --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
            --topofficial-height: calc(38px + var(--safe-top));
            --navbar-height: 72px;
            --header-total-height: calc(var(--topofficial-height) + var(--navbar-height));
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; }

        body {
            background-color: var(--bg-body);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
            padding-top: var(--header-total-height);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            touch-action: manipulation;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .rt-panel, .rt-chart-card, .rt-gradient-card { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

        /* ============ TOP OFFICIAL BAR ============ */
        .top-official-bar {
            position: fixed; top: 0; left: 0; width: 100%;
            background: var(--topofficial-bg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: nowrap; overflow: hidden;
            font-size: 11px; font-weight: 600; z-index: 1002;
            height: var(--topofficial-height);
            color: #fff;
        }
        .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; min-width: 0; flex-shrink: 1; overflow: hidden; }
        .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
        .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; flex-shrink: 0; white-space: nowrap; }
        .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
        .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        .cert-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* ============ MERGED HEADER ============ */
        .rt-topbar {
            position: fixed;
            top: var(--topofficial-height); left: 0; width: 100%;
            background: var(--navbar-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 calc(20px + var(--safe-right)) 0 calc(20px + var(--safe-left));
            height: var(--navbar-height);
            z-index: 1001;
        }

        .rt-topbar-brand {
            display: flex; align-items: center; gap: 10px;
            padding-right: 16px;
            border-right: 1px solid var(--border-color);
            flex-shrink: 0;
            height: 44px;
        }
        .rt-topbar-logo {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--red-accent), #991b1b);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25); flex-shrink: 0;
        }
        .rt-topbar-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
        .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); letter-spacing: 0.3px; }
        .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.06em; }

        /* Center */
        .rt-topbar-center {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            min-width: 0;
            height: 100%;
        }

        .rt-main-nav {
            display: flex; align-items: center; gap: 4px;
            height: 100%;
            flex-shrink: 0;
        }

        .rt-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 10px;
            color: var(--text-sub);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            background: transparent;
            border: none;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            height: 42px;
            position: relative;
        }
        .rt-nav-link i { font-size: 13px; color: var(--text-muted); transition: var(--transition); }
        .rt-nav-link:hover { background: var(--red-soft); color: var(--red-accent); }
        .rt-nav-link:hover i { color: var(--red-accent); }
        .rt-nav-link.active { background: var(--red-accent); color: #fff; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); }
        .rt-nav-link.active i { color: #fff; }

        .rt-nav-group { position: relative; display: flex; align-items: center; height: 100%; }
        .rt-nav-link .rt-caret { font-size: 9px; margin-left: 2px; transition: transform 0.25s ease; }
        .rt-nav-group:hover > .rt-nav-link .rt-caret,
        .rt-nav-group.open > .rt-nav-link .rt-caret { transform: rotate(180deg); }
        .rt-nav-group > .rt-nav-link.has-active { background: var(--red-soft); color: var(--red-accent); }
        .rt-nav-group > .rt-nav-link.has-active i { color: var(--red-accent); }

        .rt-nav-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            min-width: 250px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 8px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
            opacity: 0;
            visibility: hidden;
            transform: translateY(6px);
            transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
            z-index: 999;
        }
        .rt-nav-group:hover > .rt-nav-dropdown,
        .rt-nav-group.open > .rt-nav-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .rt-dd-label {
            font-size: 9.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--text-muted);
            padding: 6px 12px 4px 12px;
        }

        .rt-nav-dropdown a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-sub);
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
        }
        .rt-nav-dropdown a i { width: 18px; text-align: center; font-size: 13px; color: var(--red-accent); flex-shrink: 0; }
        .rt-nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
        .rt-nav-dropdown a.active { background: var(--red-soft); color: var(--red-accent); font-weight: 700; }

        .rt-dd-badge {
            margin-left: auto;
            background: #fbbf24; color: #7c2d12;
            font-size: 10px; font-weight: 800;
            padding: 2px 7px;
            border-radius: 999px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
        }

        /* ============ SEARCH TRIGGER ============ */
        .rt-search-trigger {
            position: relative;
            width: 260px;
            max-width: 100%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px 9px 38px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--bg-card-alt);
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            overflow: hidden;
            height: 42px;
        }
        .rt-search-trigger i.fa-magnifying-glass {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 13px;
        }
        .rt-search-trigger:hover {
            background: #fff;
            border-color: var(--red-accent);
            color: var(--red-accent);
        }
        .rt-search-trigger:hover i.fa-magnifying-glass { color: var(--red-accent); }
        .rt-search-trigger .search-placeholder {
            flex: 1;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .rt-search-trigger .kbd-hint {
            background: #fff;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 5px;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .rt-search-trigger:hover .kbd-hint { border-color: var(--red-accent); color: var(--red-accent); }

        /* Right side */
        .rt-topbar-right {
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }

        .icon-btn {
            width: 40px; height: 40px; border-radius: 10px;
            border: 1px solid var(--border-color); background: var(--bg-card-alt);
            color: var(--text-main); display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; transition: var(--transition);
            flex-shrink: 0;
        }
        .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
        .mobile-search-btn { display: none; }
        .mobile-menu-btn { display: none; }

        .notif-badge-dot {
            position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
            border-radius: 50%; background: var(--red-accent);
            box-shadow: 0 0 8px var(--red-accent);
            transition: opacity 0.3s ease;
        }

        .rt-profile-wrapper { position: relative; flex-shrink: 0; }
        .rt-profile-btn {
            display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
            border-radius: 999px; border: 1px solid var(--border-color);
            background: var(--bg-card-alt); cursor: pointer; transition: var(--transition);
        }
        .rt-profile-btn:hover { border-color: var(--red-accent); background: #fff; }
        .rt-profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--red-accent); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px;
        }
        .rt-profile-btn .info { display: flex; flex-direction: column; line-height: 1.1; }
        .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: var(--text-main); }
        .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

        .rt-profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 200px; background: #fff; border: 1px solid var(--border-color);
            border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: var(--transition); z-index: 100;
        }
        .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .rt-profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            border-radius: 8px; color: var(--text-sub); text-decoration: none;
            font-size: 13px; font-weight: 500; transition: var(--transition);
        }
        .rt-profile-dropdown .dropdown-item:hover { background: var(--bg-card-alt); color: var(--text-main); }
        .rt-profile-dropdown .dropdown-item.danger { color: #dc2626; }
        .rt-profile-dropdown .dropdown-item.danger:hover { background: #fee2e2; }

        /* ============ MODERN SEARCH MODAL ============ */
        .search-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 80px 16px 16px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .search-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .search-modal-card {
            width: 100%;
            max-width: 640px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transform: translateY(-20px) scale(0.96);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .search-modal-overlay.active .search-modal-card {
            transform: translateY(0) scale(1);
        }

        .search-modal-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background: #fff;
        }
        .search-modal-header .sm-icon {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--red-accent), #991b1b);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.25);
        }
        .search-modal-header .sm-input-wrap {
            flex: 1;
            position: relative;
        }
        .search-modal-header input {
            width: 100%;
            padding: 10px 0;
            border: none;
            outline: none;
            font-size: 16px;
            font-family: inherit;
            color: var(--text-main);
            background: transparent;
            font-weight: 500;
        }
        .search-modal-header input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }
        .search-modal-header .sm-esc {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 10.5px;
            font-weight: 800;
            padding: 5px 9px;
            border-radius: 6px;
            cursor: pointer;
            letter-spacing: 0.5px;
            flex-shrink: 0;
            transition: var(--transition);
            font-family: inherit;
        }
        .search-modal-header .sm-esc:hover {
            background: var(--red-soft);
            border-color: var(--red-accent);
            color: var(--red-accent);
        }

        .search-modal-body {
            max-height: 420px;
            overflow-y: auto;
            padding: 8px;
        }
        .search-modal-body::-webkit-scrollbar { width: 6px; }
        .search-modal-body::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }

        .sm-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
            gap: 8px;
        }
        .sm-state .sm-state-icon {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: var(--bg-card-alt);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            color: var(--red-accent);
            margin-bottom: 4px;
        }
        .sm-state .sm-state-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
        }
        .sm-state .sm-state-sub {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 320px;
            line-height: 1.5;
        }

        .sm-tips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
            margin-top: 10px;
        }
        .sm-tips .sm-tip {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-sub);
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
        }
        .sm-tips .sm-tip i {
            color: var(--red-accent);
            font-size: 10px;
            margin-right: 3px;
        }

        .sm-result-group-label {
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            padding: 10px 14px 4px;
        }

        .sm-result-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-main);
            transition: var(--transition);
            border: 1px solid transparent;
        }
        .sm-result-item:hover {
            background: var(--red-soft);
            border-color: #fecdd3;
        }
        .sm-result-item .sm-result-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: var(--bg-card-alt);
            color: var(--red-accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .sm-result-item:hover .sm-result-icon {
            background: var(--red-accent);
            color: #fff;
        }
        .sm-result-item .sm-result-body {
            flex: 1;
            min-width: 0;
        }
        .sm-result-item .sm-result-type {
            font-size: 9px;
            font-weight: 800;
            color: var(--red-accent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1px;
        }
        .sm-result-item .sm-result-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sm-result-item .sm-result-sub {
            font-size: 11px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sm-result-item .sm-result-arrow {
            color: var(--text-muted);
            font-size: 12px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .sm-result-item:hover .sm-result-arrow {
            color: var(--red-accent);
            transform: translateX(3px);
        }

        .search-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 16px;
            background: var(--bg-card-alt);
            border-top: 1px solid var(--border-color);
            font-size: 11px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        .search-modal-footer .sm-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .search-modal-footer kbd {
            background: #fff;
            border: 1px solid var(--border-color);
            color: var(--text-sub);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Space Grotesk', sans-serif;
            letter-spacing: 0.5px;
        }

        @keyframes smSpin {
            to { transform: rotate(360deg); }
        }
        .sm-spinner {
            display: inline-block;
            width: 20px; height: 20px;
            border: 2.5px solid var(--border-color);
            border-top-color: var(--red-accent);
            border-radius: 50%;
            animation: smSpin 0.7s linear infinite;
        }

        /* ============ DASHBOARD SHELL ============ */
        .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

        .rt-panel {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 20px;
            box-shadow: var(--shadow-soft);
        }

        .rt-panel-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
        }

        .rt-title {
            font-family: 'Space Grotesk', sans-serif;
            color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
            letter-spacing: -0.2px;
        }

        .rt-live-pill {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(5, 150, 105, 0.1); border: 1px solid rgba(5, 150, 105, 0.2);
            color: var(--emerald-accent); font-size: 11px; font-weight: 700;
            padding: 6px 12px; border-radius: 999px;
        }
        .rt-live-pill .dot {
            width: 7px; height: 7px; border-radius: 50%; background: var(--emerald-accent);
            box-shadow: 0 0 8px var(--emerald-accent);
            animation: pulseDot 1.8s ease-in-out infinite;
        }
        @keyframes pulseDot { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }

        /* ============ WELCOME BANNER ============ */
        .welcome-banner {
            background: #fff1f2;
            border-radius: 14px; padding: 22px 26px; color: var(--text-main);
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px; box-shadow: var(--shadow-soft);
            position: relative; overflow: hidden; border: 1px solid #fecdd3;
        }
        .welcome-banner::after {
            content: ''; position: absolute; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(225, 29, 72, 0.05); bottom: -60px; right: -40px; pointer-events: none;
        }
        .welcome-content { display: flex; flex-direction: column; gap: 4px; z-index: 1; width: 100%; min-width: 0; }
        .welcome-portal-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
            background: rgba(225, 29, 72, 0.1); border: 1px solid rgba(225, 29, 72, 0.2);
            border-radius: 999px; color: var(--red-accent); font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px; width: fit-content; margin-bottom: 6px;
        }
        .welcome-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px; font-weight: 800; color: #9f1239; margin: 0;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .welcome-sub {
            font-size: 12px; color: var(--text-sub); font-weight: 500;
            display: inline-flex; align-items: center; gap: 6px;
            width: fit-content; padding: 2px 8px; border-radius: 6px;
            background: rgba(225, 29, 72, 0.05); border: 1px dashed rgba(225, 29, 72, 0.3);
            max-width: 100%; word-break: break-word;
        }

        .welcome-stats-pill {
            background: #fff; border: 1px solid #fecdd3;
            padding: 12px 18px; border-radius: 12px; display: flex; flex-direction: column; gap: 6px;
            min-width: 170px; z-index: 1; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .welcome-stats-pill .top { display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; color: #9f1239; gap: 12px; }
        .welcome-progress-track { width: 100%; height: 6px; background: #fecdd3; border-radius: 999px; overflow: hidden; }
        .welcome-progress-bar { height: 100%; background: var(--red-accent); border-radius: 999px; transition: width 0.6s ease; }

        @keyframes waveAnim {
            0% { transform: rotate(0deg); }
            20% { transform: rotate(14deg); }
            40% { transform: rotate(-8deg); }
            60% { transform: rotate(14deg); }
            80% { transform: rotate(-4deg); }
            100% { transform: rotate(0deg); }
        }
        .waving-hand { display: inline-block; animation: waveAnim 2s infinite; transform-origin: 70% 70%; }

        /* ============ METRICS GRID ============ */
        .rt-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }

        .rt-gradient-card {
            border-radius: 12px; padding: 14px 16px;
            display: flex; align-items: center; justify-content: space-between;
            text-decoration: none; color: #fff; box-shadow: var(--shadow-soft);
            transition: var(--transition); position: relative; overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .rt-gradient-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lift); color: #fff; }
        .rt-gradient-card::after {
            content: ''; position: absolute; width: 100px; height: 100px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.1); bottom: -30px; right: -30px; pointer-events: none;
        }

        .rt-gradient-card.c-red { background: linear-gradient(135deg, #e11d48, #9f1239); }
        .rt-gradient-card.c-emerald { background: linear-gradient(135deg, #059669, #047857); }
        .rt-gradient-card.c-blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .rt-gradient-card.c-orange { background: linear-gradient(135deg, #ea580c, #c2410c); }

        .rt-stat-content { display: flex; flex-direction: column; gap: 2px; min-width: 0; position: relative; z-index: 1; }
        .rt-stat-title { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.85); text-transform: uppercase; letter-spacing: 0.05em; }
        .rt-stat-value { font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #fff; line-height: 1.2; word-break: break-word; }
        .rt-stat-sub { font-size: 10.5px; color: rgba(255, 255, 255, 0.8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .rt-stat-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
            background: rgba(255, 255, 255, 0.2); color: #fff; flex-shrink: 0; position: relative; z-index: 1;
        }

        /* ============ CHART CARDS ============ */
        .rt-chart-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 18px; transition: var(--transition);
            overflow: hidden; margin-bottom: 14px; box-shadow: var(--shadow-soft);
        }
        .rt-chart-card:hover { border-color: var(--border-strong); }
        .rt-chart-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; gap: 8px; flex-wrap: wrap; }
        .rt-chart-head h4 { font-family: 'Space Grotesk', sans-serif; color: var(--text-main); font-size: 14px; font-weight: 800; margin: 0; }
        .rt-chart-head a { color: var(--red-accent); font-size: 11px; text-decoration: none; font-weight: 700; }

        /* ============ NEW: CHART STYLES ============ */
        .rt-charts-grid {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        @media (max-width: 900px) {
            .rt-charts-grid { grid-template-columns: 1fr; }
        }

        .chart-canvas-wrap {
            position: relative;
            width: 100%;
            height: 260px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .chart-canvas-wrap canvas { max-height: 100%; }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            justify-content: center;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }
        .chart-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-sub);
        }
        .chart-legend-item .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        .chart-legend-item .legend-count {
            background: var(--bg-card-alt);
            color: var(--text-main);
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            border: 1px solid var(--border-color);
        }

        .chart-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 260px;
            gap: 8px;
            color: var(--text-muted);
            font-size: 12px;
            text-align: center;
        }
        .chart-empty i { font-size: 32px; opacity: 0.35; color: var(--red-accent); }

        .rt-grid { display: grid; gap: 14px; }
        .rt-grid-2col { grid-template-columns: 1.2fr 1fr; }
        @media (max-width: 700px) { .rt-grid-2col { grid-template-columns: 1fr; } }

        /* ============ DATA TABLE ============ */
        .rt-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .rt-table { width: 100%; border-collapse: collapse; font-size: 13px; color: var(--text-main); margin-bottom: 0; }
        .rt-table thead th {
            font-size: 10.5px; text-transform: uppercase; color: var(--text-muted);
            border-bottom: 1px solid var(--border-color); padding: 10px 14px;
            font-weight: 700; background: var(--bg-card-alt); white-space: nowrap;
        }
        .rt-table tbody td {
            padding: 11px 14px; font-size: 12.5px;
            border-bottom: 1px solid var(--border-color); color: var(--text-main);
            vertical-align: middle;
        }
        .rt-table tbody tr:hover { background: var(--red-soft); }
        .rt-table tbody tr:last-child td { border-bottom: none; }

        .rental-cell { display: flex; align-items: center; gap: 10px; font-weight: 700; color: var(--text-main); }
        .rental-cell .rental-icon {
            width: 28px; height: 28px; border-radius: 8px; background: var(--red-soft); color: var(--red-accent);
            display: flex; align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0; border: 1px solid #fecdd3;
        }

        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-badge.scheduled { background: var(--red-soft); color: var(--red-accent); border: 1px solid #fecdd3; }
        .status-badge.pending   { background: rgba(245, 158, 11, 0.14); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.35); }

        .permit-badge { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; }
        .permit-badge.success { background: rgba(16, 185, 129, 0.12); color: #047857; }
        .permit-badge.warning { background: rgba(245, 158, 11, 0.14); color: #b45309; }
        .permit-badge.danger  { background: rgba(239, 68, 68, 0.12); color: #b91c1c; }
        .permit-badge.secondary { background: var(--bg-card-alt); color: var(--text-muted); }

        /* ============ ACTIVITY FEED ============ */
        .activity-feed {
            display: flex; flex-direction: column; gap: 8px;
            max-height: 320px; overflow-y: auto; padding-right: 4px;
        }
        .activity-feed::-webkit-scrollbar { width: 4px; }
        .activity-feed::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }
        .activity-item {
            display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px;
            background: var(--bg-card-alt); border: 1px solid var(--border-color);
            border-radius: 10px; transition: var(--transition);
        }
        .activity-item:hover { border-color: var(--border-strong); background: #fff; }
        .activity-item .act-icon {
            width: 28px; height: 28px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; flex-shrink: 0;
        }
        .activity-item .act-icon.inspection { background: rgba(234, 88, 12, 0.15); color: #ea580c; }
        .activity-item .act-icon.completed  { background: rgba(5, 150, 105, 0.15); color: #059669; }
        .activity-item .act-icon.failed     { background: rgba(220, 38, 38, 0.15); color: #dc2626; }
        .activity-item .act-body { flex: 1; min-width: 0; }
        .activity-item .act-desc { font-size: 12px; font-weight: 600; color: var(--text-main); line-height: 1.4; word-break: break-word; }
        .activity-item .act-time { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        /* ============ QUICK ACTIONS ============ */
        .quick-actions-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .quick-action-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; padding: 14px 10px; background: var(--bg-card-alt);
            border: 1px solid var(--border-color); border-radius: 10px;
            text-decoration: none; color: var(--text-main); transition: var(--transition);
        }
        .quick-action-btn:hover { background: var(--red-soft); transform: translateY(-2px); border-color: var(--red-accent); color: var(--red-accent); }
        .quick-action-btn i { font-size: 18px; color: var(--red-accent); }
        .quick-action-btn span { font-size: 11px; font-weight: 700; text-align: center; }

        /* ============ FOOTER ============ */
        .footer {
            margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
            border-top: 1px solid var(--border-color); display: flex;
            justify-content: space-between; align-items: center; font-size: 11.5px;
            color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
        }

        #scrollTopBtn {
            position: fixed; bottom: calc(90px + var(--safe-bottom)); right: 25px;
            width: 46px; height: 46px; background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #fff; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
            cursor: pointer; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4);
            z-index: 9998; opacity: 0; visibility: hidden; transform: translateY(15px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #scrollTopBtn.show { opacity: 1; visibility: visible; transform: translateY(0); }
        #scrollTopBtn:hover { background: linear-gradient(135deg, #ef4444, #dc2626); transform: translateY(-3px); }

        /* ============ CHAT WIDGET ============ */
        .chat-widget-container { position: fixed; bottom: 20px; right: 20px; z-index: 99999; }
        .chat-toggle-btn {
            width: 56px; height: 56px; background: linear-gradient(135deg, var(--red-accent), #991b1b);
            border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.6); cursor: pointer; border: 2px solid rgba(255, 255, 255, 0.6);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .chat-toggle-btn:hover { transform: scale(1.08); }

        .chat-box-card {
            position: fixed; bottom: 90px; right: 12px; left: 12px; width: auto;
            height: 70vh; max-height: 560px; min-height: 380px;
            background: #1f1315; border-radius: 16px;
            border: 1px solid rgba(220, 38, 38, 0.35);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            display: flex; flex-direction: column; overflow: hidden;
            opacity: 0; pointer-events: none;
            transform: translateY(20px) scale(0.96);
            transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 999999; transform-origin: bottom right;
        }
        .chat-box-card.active { opacity: 1; pointer-events: auto; transform: translateY(0) scale(1); }
        @media (min-width: 576px) {
            .chat-box-card {
                left: auto; right: 20px; bottom: 90px; width: 380px; height: 520px;
                max-height: calc(100vh - 120px); min-height: 400px;
                transform: translateY(15px) scale(0.95);
            }
        }
        @media (min-width: 992px) { .chat-box-card { width: 400px; height: 560px; } }

        .chat-header {
            background: #140a0c; padding: 12px 14px; border-bottom: 1px solid rgba(220, 38, 38, 0.25);
            display: flex; align-items: center; justify-content: space-between; color: #fff; flex-shrink: 0;
        }
        .chat-header .title-area { display: flex; align-items: center; gap: 10px; }
        .chat-header .status-dot { width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981; }
        .chat-header .title-area .subtext { font-size: 10.5px; color: #fecdd3; }
        .chat-header .title-area .title-text { font-size: 13.5px; font-weight: 700; line-height: 1.2; }

        .chat-body {
            flex: 1; padding: 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;
            background: #140a0c; -webkit-overflow-scrolling: touch;
        }
        .chat-message { max-width: 85%; padding: 9px 13px; border-radius: 12px; font-size: 13px; line-height: 1.4; word-break: break-word; }
        .chat-message.incoming { background: #2e1c1f; color: #f3f4f6; align-self: flex-start; border: 1px solid rgba(220, 38, 38, 0.25); border-bottom-left-radius: 4px; }
        .chat-message.outgoing { background: var(--red-accent); color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }
        .chat-message img { max-width: 100%; border-radius: 8px; margin-top: 6px; display: block; }
        .chat-message a { color: #fff; text-decoration: underline; }

        .chat-footer {
            padding: 10px 12px; background: #140a0c; border-top: 1px solid rgba(220, 38, 38, 0.25);
            display: flex; flex-direction: column; gap: 6px; flex-shrink: 0;
            padding-bottom: max(10px, env(safe-area-inset-bottom));
        }
        .chat-input-row { display: flex; gap: 8px; align-items: center; }
        .chat-footer input[type="text"] {
            flex: 1; min-width: 0; background: #0a0506; border: 1px solid rgba(220, 38, 38, 0.25); padding: 10px 14px; border-radius: 10px;
            color: #fff; font-size: 14px; outline: none;
        }
        .chat-footer input[type="text"]:focus { border-color: var(--red-accent); }
        .chat-btn-action, .chat-send-btn {
            background: rgba(220, 38, 38, 0.15); border: 1px solid rgba(220, 38, 38, 0.35); color: #fecdd3;
            width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; flex-shrink: 0; font-size: 15px;
        }
        .chat-send-btn { background: var(--red-accent); color: #fff; border: none; }
        .chat-send-btn:hover { background: #991b1b; }
        .chat-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        #selectedFilePreview {
            font-size: 11.5px; color: #fecdd3; display: none; align-items: center; gap: 6px;
            padding: 6px 10px; background: rgba(220, 38, 38, 0.1); border-radius: 8px; border: 1px solid rgba(220, 38, 38, 0.35);
        }
        #selectedFilePreview span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .chat-backdrop {
            position: fixed; inset: 0; background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px); z-index: 999998;
            opacity: 0; pointer-events: none; transition: opacity 0.28s ease;
        }
        .chat-backdrop.active { opacity: 1; pointer-events: auto; }
        @media (min-width: 576px) { .chat-backdrop { display: none !important; } }

        /* ============ MODALS ============ */
        .modal-content {
            background: #ffffff !important; border: 1px solid var(--border-color) !important;
            color: #0f172a !important; border-radius: 16px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .modal-content .modal-header { border-bottom: 1px solid var(--border-color) !important; }
        .modal-content .modal-title { color: var(--red-accent) !important; }
        .modal-content .modal-body p { color: #475569 !important; }
        .modal-content .modal-footer { border-top: 1px solid var(--border-color) !important; }
        .modal-content .btn-secondary {
            background: #f1f5f9 !important; border-color: var(--border-color) !important; color: #0f172a !important;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1400px) {
            .rt-search-trigger { width: 220px; }
        }

        @media (max-width: 1200px) {
            .rt-topbar-center { gap: 12px; }
            .rt-search-trigger { width: 180px; }
            .rt-search-trigger .kbd-hint { display: none; }
            .rt-nav-link { padding: 8px 10px; font-size: 12.5px; }
        }

        @media (max-width: 992px) {
            .mobile-menu-btn { display: flex; }

            .rt-topbar-center { display: contents; }

            .rt-main-nav {
                display: none;
                position: fixed;
                top: var(--header-total-height);
                left: 0; width: 100%;
                height: auto;
                max-height: calc(100vh - var(--header-total-height));
                background: var(--navbar-bg);
                border-bottom: 1px solid var(--border-color);
                flex-direction: column;
                align-items: stretch;
                padding: 10px 16px 14px;
                gap: 4px;
                overflow-y: auto;
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
                z-index: 1001;
            }
            body.mobile-nav-open .rt-main-nav { display: flex; }

            .rt-nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 12px 14px;
                height: auto;
                font-size: 13.5px;
                border-radius: 10px;
            }
            .rt-nav-link.active { box-shadow: none; }

            .rt-nav-group {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
                height: auto;
            }
            .rt-nav-group > .rt-nav-link { justify-content: space-between; }

            .rt-nav-dropdown {
                position: static;
                opacity: 1;
                visibility: visible;
                transform: none;
                box-shadow: none;
                background: var(--bg-card-alt);
                margin: 4px 0 6px 0;
                border: 1px solid var(--border-color);
                padding: 6px;
                display: none;
                border-radius: 10px;
            }
            .rt-nav-group.open > .rt-nav-dropdown { display: block; }
            .rt-nav-group:hover > .rt-nav-dropdown { display: none; }
            .rt-nav-group.open:hover > .rt-nav-dropdown { display: block; }

            .rt-search-trigger { display: none; }
            .mobile-search-btn { display: flex; }

            .rt-topbar-brand { border-right: none; padding-right: 0; }
        }

        @media (max-width: 768px) {
            .rt-topbar-brand-text strong { font-size: 12px; }
            .rt-topbar-right { gap: 8px; }
            .rt-metrics-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .rt-gradient-card { padding: 12px 14px; }
            .rt-stat-value { font-size: 18px; }
            .rt-stat-icon { width: 32px; height: 32px; font-size: 13px; }
            .dashboard-shell { padding: 14px 14px 24px; }
            .rt-panel { padding: 16px; }
            .welcome-title { font-size: 20px; }
            .rt-title { font-size: 18px; }
        }

        @media (max-width: 576px) {
            .rt-topbar { padding: 0 calc(10px + var(--safe-right)) 0 calc(10px + var(--safe-left)); gap: 6px; }
            .rt-topbar-brand { padding-right: 0; gap: 8px; height: 40px; }
            .rt-topbar-brand-text { display: none; }
            .rt-topbar-logo { width: 32px; height: 32px; font-size: 12px; }
            .rt-profile-btn { padding: 4px; }
            .rt-profile-btn .info { display: none !important; }
            .rt-profile-btn i { display: none; }
            .dashboard-shell { padding: 10px 10px 20px; }
            .rt-panel { padding: 12px; border-radius: 12px; }
            .rt-metrics-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .rt-gradient-card { padding: 10px 12px; border-radius: 10px; }
            .rt-stat-title { font-size: 9px; }
            .rt-stat-value { font-size: 16px; }
            .rt-stat-sub { font-size: 9px; }
            .rt-stat-icon { width: 28px; height: 28px; font-size: 11px; border-radius: 8px; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .welcome-banner { padding: 16px 14px; border-radius: 12px; }
            .welcome-title { font-size: 17px; gap: 6px; }
            .welcome-portal-badge { font-size: 9px; padding: 2px 8px; }
            .welcome-sub { font-size: 10.5px; }
            .rt-chart-card { padding: 12px; border-radius: 12px; }
            .rt-chart-head h4 { font-size: 12.5px; }
            .quick-action-btn { padding: 12px 8px; }
            .quick-action-btn i { font-size: 16px; }
            .quick-action-btn span { font-size: 10px; }
            .footer { font-size: 10px; flex-direction: column; text-align: center; }
            #scrollTopBtn { width: 40px; height: 40px; font-size: 15px; bottom: calc(88px + var(--safe-bottom)); right: 18px; }
            .rt-title { font-size: 16px; }

            .search-modal-overlay { padding: 60px 10px 10px; }
            .search-modal-card { border-radius: 14px; }
            .search-modal-header { padding: 12px 14px; gap: 10px; }
            .search-modal-header .sm-icon { width: 34px; height: 34px; font-size: 13px; }
            .search-modal-header input { font-size: 15px; }
            .search-modal-body { max-height: 60vh; }

            /* ✅ NEW: chart mobile adjustments */
            .chart-canvas-wrap { height: 220px; }
            .chart-empty { height: 220px; }
            .chart-legend { gap: 6px 10px; }
            .chart-legend-item { font-size: 10.5px; }
        }

        @media (max-width: 380px) {
            .rt-metrics-grid { grid-template-columns: 1fr; }
            .quick-actions-grid { grid-template-columns: 1fr; }
            .welcome-title { font-size: 15px; }
            .rt-stat-value { font-size: 18px; }
            .dashboard-shell { padding: 8px; }
            .rt-panel { padding: 10px; }
            .rt-chart-card { padding: 10px; }
        }

        @supports (-webkit-touch-callout: none) {
            input, button, select, textarea { font-size: 16px !important; }
            .search-modal-header input { font-size: 16px !important; }
        }

        @media print {
            .top-official-bar, .rt-topbar, #scrollTopBtn, .footer, .chat-widget-container, .chat-box-card, .search-modal-overlay { display: none !important; }
            body { padding-top: 0; background: #fff; color: #000; }
            .rt-panel, .rt-chart-card, .rt-gradient-card { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<!-- ============ TOP OFFICIAL BAR ============ -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">LGU Rental Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> V2.1</span>
        <span class="d-none d-lg-inline" style="font-size:11px; color:#fecdd3;">Inspector Field Operations</span>
    </div>
</div>

<!-- ============ MERGED HEADER ============ -->
<header class="rt-topbar">

    <button class="icon-btn mobile-menu-btn" id="mobileMenuBtn" title="Menu">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="rt-topbar-brand">
        <div class="rt-topbar-logo">RT</div>
        <div class="rt-topbar-brand-text">
            <strong>RENTRACK</strong>
            <span>INSPECTOR</span>
        </div>
    </div>

    <div class="rt-topbar-center">
        <nav class="rt-main-nav" id="rtMainNav">
            <a href="dashboard.php" class="rt-nav-link active">
                <i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span>
            </a>

            <!-- ✅ FIELD OPS -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn">
                    <i class="fa-solid fa-clipboard-check"></i> <span>Field Ops</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="inspections.php">
                        <i class="fa-solid fa-clipboard-check"></i> Inspections
                        <?php if ($unreadInspectionsCount > 0): ?>
                            <span class="rt-dd-badge"><?php echo $unreadInspectionsCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="calendar.php"><i class="fa-regular fa-calendar"></i> Calendar</a>
                    <a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> Findings</a>
                </div>
            </div>

            <!-- ✅ REPORTS -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn">
                    <i class="fa-solid fa-file-lines"></i> <span>Reports</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="properties.php"><i class="fa-solid fa-building"></i> Properties</a>
                    <a href="reports.php"><i class="fa-solid fa-file-lines"></i> Reports</a>
                </div>
            </div>

            <!-- Account (unchanged) -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn">
                    <i class="fa-solid fa-user"></i> <span>Account</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a>
                    <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
                </div>
            </div>
        </nav>

        <div class="rt-search-trigger" id="openSearchBtn" role="button" tabindex="0">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span class="search-placeholder">Search properties, owners...</span>
            <span class="kbd-hint">Ctrl K</span>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>

            <div id="notifDropdown" style="display:none; position:absolute; top:48px; right:0; width:340px; max-width:calc(100vw - 20px); background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">Notifications</span>
                </div>
                <div id="notifListContainer" style="max-height: 350px; overflow-y: auto;">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo htmlspecialchars($n['href']); ?>" 
                               class="notif-item d-block text-decoration-none" 
                               style="padding:9px 8px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; transition:background 0.2s;" 
                               onmouseover="this.style.background='var(--red-soft)'" 
                               onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);">
                                    <i class="fa-solid <?php echo $n['icon']; ?> me-1" style="color:var(--red-accent);"></i>
                                    <?php echo htmlspecialchars($n['title']); ?>
                                </div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px; line-height:1.3;"><?php echo htmlspecialchars($n['sub']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">
                            <i class="fa-regular fa-circle-check me-1"></i> You're all caught up.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo $fullname; ?></span>
                    <span class="role"><?php echo $staffRole; ?></span>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; color:var(--text-muted);"></i>
            </div>

            <div class="rt-profile-dropdown" id="profileDropdown">
                <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                <div style="height:1px; background:var(--border-color); margin:4px 0;"></div>
                <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- ============ MODERN SEARCH MODAL ============ -->
<div class="search-modal-overlay" id="searchModalOverlay">
    <div class="search-modal-card" role="dialog" aria-modal="true">
        <div class="search-modal-header">
            <div class="sm-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="sm-input-wrap">
                <input type="text" id="searchModalInput" placeholder="Search properties, owners, or tracking number..." autocomplete="off" spellcheck="false">
            </div>
            <button type="button" class="sm-esc" id="searchModalClose">ESC</button>
        </div>

        <div class="search-modal-body" id="searchModalBody">
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Records</div>
                <div class="sm-state-sub">Type at least 2 characters to search properties, business names, or tracking numbers.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Business name</span>
                    <span class="sm-tip"><i class="fa-solid fa-user"></i> Owner name</span>
                    <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking number</span>
                </div>
            </div>
        </div>

        <div class="search-modal-footer">
            <span class="sm-hint"><kbd>↑</kbd><kbd>↓</kbd> Navigate</span>
            <span class="sm-hint"><kbd>Enter</kbd> Open</span>
            <span class="sm-hint"><kbd>ESC</kbd> Close</span>
        </div>
    </div>
</div>

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">
            <div class="rt-panel">

                <div class="welcome-banner">
                    <div class="welcome-content">
                        <span class="welcome-portal-badge"><i class="fa-solid fa-shield-halved"></i> INSPECTOR PORTAL</span>
                        <h2 class="welcome-title">
                            <?php echo $greeting; ?>, <?php echo $fullname; ?>! <span class="waving-hand">👋</span>
                        </h2>
                        <span class="welcome-sub">
                            <i class="fa-regular fa-calendar me-1"></i> <?php echo $todayLabel; ?>
                        </span>
                    </div>
                    <div class="welcome-stats-pill">
                        <div class="top">
                            <span>Completion Rate</span>
                            <span><strong><?php echo $completionRate; ?>%</strong></span>
                        </div>
                        <div class="welcome-progress-track">
                            <div class="welcome-progress-bar" style="width: <?php echo $completionRate; ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div class="rt-panel-head">
                    <div>
                        <h2 class="rt-title">Field Operations Overview</h2>
                    </div>
                    <span class="rt-live-pill"><span class="dot"></span> Live data &middot; auto-synced</span>
                </div>

                <div class="rt-metrics-grid">
                    <a href="inspections.php" class="rt-gradient-card c-red">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Assigned</span>
                            <div class="rt-stat-value"><?php echo number_format($totalAssigned); ?></div>
                            <span class="rt-stat-sub">Active items</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    </a>

                    <a href="inspections.php?status=Completed" class="rt-gradient-card c-emerald">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Completed</span>
                            <div class="rt-stat-value"><?php echo number_format($totalCompleted); ?></div>
                            <span class="rt-stat-sub"><?php echo $completionRate; ?>% Rate</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </a>

                    <a href="inspections.php?status=Scheduled" class="rt-gradient-card c-blue">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Scheduled</span>
                            <div class="rt-stat-value"><?php echo number_format($totalScheduled); ?></div>
                            <span class="rt-stat-sub">Upcoming queue</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-clock"></i></div>
                    </a>

                    <a href="inspections.php?status=Failed" class="rt-gradient-card c-orange">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Failed</span>
                            <div class="rt-stat-value"><?php echo number_format($totalFailed); ?></div>
                            <span class="rt-stat-sub">Needs action</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                    </a>
                </div>

                <!-- ============ ✅ NEW: CHARTS SECTION ============ -->
                <div class="rt-charts-grid">

                    <!-- Doughnut Chart: Status Breakdown -->
                    <div class="rt-chart-card" style="margin-bottom:0;">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-chart-pie me-2" style="color:var(--red-accent);"></i> Status Breakdown</h4>
                            <span style="font-size:10px; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                <?php echo number_format($statusChartTotal); ?> total
                            </span>
                        </div>
                        <div class="chart-canvas-wrap">
                            <?php if ($statusChartTotal > 0): ?>
                                <canvas id="statusDoughnutChart"></canvas>
                            <?php else: ?>
                                <div class="chart-empty">
                                    <i class="fa-regular fa-chart-bar"></i>
                                    <span>No inspection data yet</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($statusChartTotal > 0): ?>
                        <div class="chart-legend">
                            <?php 
                            $legendColors = [
                                'Completed' => '#059669',
                                'Scheduled' => '#2563eb',
                                'Ongoing'   => '#d97706',
                                'Failed'    => '#dc2626',
                                'Pending'   => '#7c3aed',
                            ];
                            foreach ($statusChartData as $label => $val): 
                                if ($val <= 0) continue;
                            ?>
                                <div class="chart-legend-item">
                                    <span class="legend-dot" style="background:<?php echo $legendColors[$label]; ?>;"></span>
                                    <?php echo $label; ?>
                                    <span class="legend-count"><?php echo $val; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Bar Chart: 6-Month Trend -->
                    <div class="rt-chart-card" style="margin-bottom:0;">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-chart-column me-2" style="color:var(--red-accent);"></i> 6-Month Inspection Trend</h4>
                            <span style="font-size:10px; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                <i class="fa-solid fa-circle me-1" style="color:#059669; font-size:6px;"></i> Completed
                                <i class="fa-solid fa-circle ms-2 me-1" style="color:#dc2626; font-size:6px;"></i> Failed
                                <i class="fa-solid fa-circle ms-2 me-1" style="color:#cbd5e1; font-size:6px;"></i> Total
                            </span>
                        </div>
                        <div class="chart-canvas-wrap">
                            <?php if (!empty($monthlyTrend)): ?>
                                <canvas id="monthlyTrendChart"></canvas>
                            <?php else: ?>
                                <div class="chart-empty">
                                    <i class="fa-regular fa-chart-bar"></i>
                                    <span>No inspection history for the last 6 months</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
                <!-- ============ ✅ END CHARTS SECTION ============ -->

                <div class="rt-chart-card">
                    <div class="rt-chart-head">
                        <h4><i class="fa-solid fa-bolt me-2" style="color:var(--red-accent);"></i> Quick Actions</h4>
                    </div>
                    <div class="quick-actions-grid">
                        <a href="inspections.php" class="quick-action-btn">
                            <i class="fa-solid fa-clipboard-check"></i>
                            <span>My Inspections</span>
                        </a>
                        <a href="findings.php" class="quick-action-btn">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Record Findings</span>
                        </a>
                        <a href="calendar.php" class="quick-action-btn">
                            <i class="fa-solid fa-calendar-week"></i>
                            <span>Calendar View</span>
                        </a>
                        <a href="properties.php" class="quick-action-btn">
                            <i class="fa-solid fa-building"></i>
                            <span>Properties</span>
                        </a>
                    </div>
                </div>

                <div class="rt-grid rt-grid-2col">
                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-regular fa-clock me-2" style="color:var(--red-accent);"></i> Upcoming Inspections</h4>
                            <a href="inspections.php">View all &rarr;</a>
                        </div>
                        <div class="rt-table-wrap">
                            <table class="rt-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Schedule</th>
                                        <th>Permit</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($upcomingInspections)): ?>
                                        <?php foreach ($upcomingInspections as $row):
                                            $displayDate = !empty($row['inspection_date']) ? date('M d, Y', strtotime($row['inspection_date'])) : 'TBD';
                                            $displayTime = !empty($row['inspection_time']) ? date('g:i A', strtotime($row['inspection_time'])) : '';
                                            $permit      = getPermitStatus($row['permit_expiry'] ?? null);
                                            $statusText  = trim((string) ($row['inspection_status'] ?? 'Pending'));
                                            $statusKey   = strtolower($statusText);
                                            $badgeClass  = in_array($statusKey, ['scheduled', 'ongoing'], true) ? 'scheduled' : 'pending';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="rental-cell">
                                                    <div class="rental-icon"><i class="fa-solid fa-building"></i></div>
                                                    <span>
                                                        <?php echo htmlspecialchars($row['rental_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php if (!empty($row['barangay'])): ?>
                                                            <br><small class="text-muted" style="font-size:10.5px;"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars($row['barangay'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo $displayDate; ?>
                                                <?php if ($displayTime !== ''): ?><br><small class="text-muted" style="font-size:10.5px;"><?php echo $displayTime; ?></small><?php endif; ?>
                                            </td>
                                            <td><span class="permit-badge <?php echo $permit['class']; ?>"><?php echo htmlspecialchars($permit['label']); ?></span></td>
                                            <td><span class="status-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align:center; padding:24px; color:var(--text-muted); font-size:12px;">
                                                <i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.4; display:block; margin-bottom:6px;"></i>
                                                No upcoming inspections
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--red-accent);"></i> Recent Activity</h4>
                        </div>
                        <div class="activity-feed">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <?php
                                    $actText  = trim((string) ($activity['status'] ?? 'Pending'));
                                    $actKey   = strtolower($actText);
                                    $isGood   = in_array($actKey, ['completed', 'passed'], true);
                                    $isBad    = in_array($actKey, ['failed', 'cancelled'], true);
                                    $iconClass = $isGood ? 'completed' : ($isBad ? 'failed' : 'inspection');
                                    $iconName  = $isGood ? 'check' : ($isBad ? 'xmark' : 'hourglass-half');
                                    ?>
                                    <div class="activity-item">
                                        <div class="act-icon <?php echo $iconClass; ?>">
                                            <i class="fa-solid fa-<?php echo $iconName; ?>"></i>
                                        </div>
                                        <div class="act-body">
                                            <div class="act-desc"><?php echo htmlspecialchars($activity['title'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="act-time">
                                                <i class="fa-regular fa-clock me-1"></i>
                                                Inspection <?php echo htmlspecialchars($actText, ENT_QUOTES, 'UTF-8'); ?>
                                                &middot; <?php echo !empty($activity['date']) ? date('M d, Y', strtotime($activity['date'])) : '—'; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:24px; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-regular fa-inbox" style="font-size:24px; opacity:0.4; display:block; margin-bottom:6px;"></i>
                                    No recent activity
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Inspector Portal</span>
                <span>Field Operations &amp; Compliance System</span>
            </footer>
        </div>
    </div>
</div>

<button id="scrollTopBtn" title="Scroll to top">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<!-- CHAT WIDGET -->
<div class="chat-backdrop" id="chatBackdrop"></div>

<div class="chat-widget-container">
    <div class="chat-toggle-btn" id="chatToggleBtn" title="Open Chat">
        <i class="fa-solid fa-comments"></i>
    </div>
</div>

<div class="chat-box-card" id="chatBoxCard">
    <div class="chat-header">
        <div class="title-area">
            <div class="status-dot"></div>
            <div>
                <div class="title-text">Staff Portal Chat</div>
                <div class="subtext">LGU Support &amp; Coordination</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm text-danger p-0 bg-transparent border-0" id="clearChatBtn" title="Clear Messages" style="font-size: 15px;"><i class="fa-solid fa-trash-can"></i></button>
            <button class="btn btn-sm text-white p-0 bg-transparent border-0" id="closeChatBtn" title="Close" style="font-size: 18px;"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>

    <div class="chat-body" id="chatBody">
        <div class="chat-message incoming">Connecting to live chat...</div>
    </div>
    <div class="chat-footer">
        <div id="selectedFilePreview">
            <i class="fa-solid fa-paperclip"></i> <span id="fileNameDisplay">file.jpg</span>
            <i class="fa-solid fa-xmark" id="removeFileBtn" style="cursor:pointer;"></i>
        </div>
        <div class="chat-input-row">
            <label for="chatFileInput" class="chat-btn-action" title="Attach"><i class="fa-solid fa-plus"></i></label>
            <input type="file" id="chatFileInput" style="display:none;" accept="image/*,.pdf,.doc,.docx">
            <input type="text" id="chatInputMessage" placeholder="Type a message..." autocomplete="off" enterkeyhint="send">
            <button class="chat-send-btn" id="chatSendBtn" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="clearChatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="font-size: 15px;"><i class="fa-solid fa-triangle-exclamation me-2"></i> Clear History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="small mb-0">Are you sure you want to clear all conversation history with Staff?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal" style="font-size: 12px;">Cancel</button>
                <button type="button" class="btn btn-danger px-4 py-2" id="confirmClearChatBtn" style="font-size: 12px; background: #dc2626; border: none;">Yes, Clear</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="font-size: 15px;"><i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="small mb-0">Are you sure you want to log out from your session?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal" style="font-size: 12px;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn btn-danger px-4 py-2" style="font-size: 12px; background: #dc2626; border: none;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token']); ?>;

    function ajaxJson(res) {
        if (res.status === 401) { window.location.reload(); throw new Error('Session expired'); }
        return res.json();
    }

    /* ============ SCROLL TOP ============ */
    const scrollTopBtn = document.getElementById('scrollTopBtn');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 300) scrollTopBtn.classList.add('show');
        else scrollTopBtn.classList.remove('show');
    });
    scrollTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

    /* ============ MOBILE MENU ============ */
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.body.classList.toggle('mobile-nav-open');
        });
    }

    /* ============ NAV DROPDOWN TOGGLE ============ */
    document.querySelectorAll('.rt-nav-group > .rt-nav-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const group = btn.parentElement;
            document.querySelectorAll('.rt-nav-group').forEach(g => {
                if (g !== group) g.classList.remove('open');
            });
            group.classList.toggle('open');
        });
    });

    document.addEventListener('click', (e) => {
        const nav = document.getElementById('rtMainNav');
        if (!nav || !nav.contains(e.target)) {
            document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
            if (!e.target.closest('#mobileMenuBtn')) {
                document.body.classList.remove('mobile-nav-open');
            }
        }
        if (profileDropdown) profileDropdown.classList.remove('show');
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    document.querySelectorAll('.rt-nav-dropdown a, .rt-nav-link:not(.rt-nav-btn)').forEach(link => {
        link.addEventListener('click', () => {
            document.body.classList.remove('mobile-nav-open');
        });
    });

    /* ============ NOTIFICATIONS ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
            if (!isOpen && notifBadgeDot) {
                notifBadgeDot.style.opacity = '0';
                setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
            }
        });
    }

    /* ============ PROFILE DROPDOWN ============ */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    if (profileToggle) {
        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });
    }

    /* ============ LOGOUT ============ */
    document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        new bootstrap.Modal(document.getElementById('logoutModal')).show();
    });

    /* =========================================================
       MODERN SEARCH MODAL
    ========================================================= */
    const searchOverlay = document.getElementById('searchModalOverlay');
    const searchInput = document.getElementById('searchModalInput');
    const searchBody = document.getElementById('searchModalBody');
    const openSearchBtn = document.getElementById('openSearchBtn');
    const searchModalCloseBtn = document.getElementById('searchModalClose');
    let searchDebounce = null;
    let currentResults = [];

    function openSearchModal() {
        searchOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => searchInput.focus(), 120);
    }

    function closeSearchModal() {
        searchOverlay.classList.remove('active');
        document.body.style.overflow = '';
        searchInput.value = '';
        resetSearchBody();
    }

    function resetSearchBody() {
        searchBody.innerHTML = `
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Records</div>
                <div class="sm-state-sub">Type at least 2 characters to search properties, business names, or tracking numbers.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Business name</span>
                    <span class="sm-tip"><i class="fa-solid fa-user"></i> Owner name</span>
                    <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking number</span>
                </div>
            </div>
        `;
    }

    openSearchBtn?.addEventListener('click', openSearchModal);
    openSearchBtn?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openSearchModal(); }
    });
    document.getElementById('mobileSearchBtn')?.addEventListener('click', openSearchModal);
    searchModalCloseBtn?.addEventListener('click', closeSearchModal);

    searchOverlay?.addEventListener('click', (e) => {
        if (e.target === searchOverlay) closeSearchModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchOverlay.classList.contains('active')) {
            closeSearchModal();
            return;
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openSearchModal();
        }
    });

    searchInput?.addEventListener('input', (e) => {
        clearTimeout(searchDebounce);
        const q = e.target.value.trim();

        if (q.length < 2) {
            resetSearchBody();
            return;
        }

        searchBody.innerHTML = `
            <div class="sm-state">
                <span class="sm-spinner"></span>
                <div class="sm-state-sub" style="margin-top:6px;">Searching records...</div>
            </div>
        `;

        searchDebounce = setTimeout(() => {
            fetch('dashboard.php?ajax_action=global_search&q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    currentResults = data;
                    renderSearchResults(data, q);
                })
                .catch(err => {
                    console.error('Search error:', err);
                    searchBody.innerHTML = `
                        <div class="sm-state">
                            <div class="sm-state-icon" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i></div>
                            <div class="sm-state-title">Search failed</div>
                            <div class="sm-state-sub">Please check your connection and try again.</div>
                        </div>
                    `;
                });
        }, 280);
    });

    function renderSearchResults(data, q) {
        searchBody.innerHTML = '';
        if (!data || data.length === 0) {
            searchBody.innerHTML = `
                <div class="sm-state">
                    <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div class="sm-state-title">No results found</div>
                    <div class="sm-state-sub">No records matched "<strong>${escapeHtml(q)}</strong>". Try a different keyword.</div>
                </div>
            `;
            return;
        }

        const groups = {};
        data.forEach(item => {
            if (!groups[item.type]) groups[item.type] = [];
            groups[item.type].push(item);
        });

        Object.keys(groups).forEach(type => {
            const label = document.createElement('div');
            label.className = 'sm-result-group-label';
            label.textContent = type + (groups[type].length > 1 ? 's' : '') + ' · ' + groups[type].length;
            searchBody.appendChild(label);

            groups[type].forEach(item => {
                const a = document.createElement('a');
                a.href = item.link;
                a.className = 'sm-result-item';
                a.innerHTML = `
                    <div class="sm-result-icon"><i class="fa-solid ${item.icon || 'fa-file'}"></i></div>
                    <div class="sm-result-body">
                        <div class="sm-result-type">${escapeHtml(item.type)}</div>
                        <div class="sm-result-title">${escapeHtml(item.title)}</div>
                        <div class="sm-result-sub">${escapeHtml(item.sub)}</div>
                    </div>
                    <i class="fa-solid fa-arrow-right sm-result-arrow"></i>
                `;
                searchBody.appendChild(a);
            });
        });
    }

    /* ============ CHAT WIDGET ============ */
    const chatToggleBtn = document.getElementById('chatToggleBtn');
    const chatBoxCard = document.getElementById('chatBoxCard');
    const chatBackdrop = document.getElementById('chatBackdrop');
    const closeChatBtn = document.getElementById('closeChatBtn');
    const clearChatBtn = document.getElementById('clearChatBtn');
    const confirmClearChatBtn = document.getElementById('confirmClearChatBtn');
    const chatSendBtn = document.getElementById('chatSendBtn');
    const chatInputMessage = document.getElementById('chatInputMessage');
    const chatBody = document.getElementById('chatBody');
    const chatFileInput = document.getElementById('chatFileInput');
    const selectedFilePreview = document.getElementById('selectedFilePreview');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const removeFileBtn = document.getElementById('removeFileBtn');

    const clearChatModal = new bootstrap.Modal(document.getElementById('clearChatModal'));
    let attachedFile = null;
    let chatOpen = false;

    function openChat() {
        chatOpen = true;
        chatBoxCard.classList.add('active');
        if (window.innerWidth < 576) {
            chatBackdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        chatInputMessage.focus();
        fetchChatMessages();
    }

    function closeChat() {
        chatOpen = false;
        chatBoxCard.classList.remove('active');
        chatBackdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    chatToggleBtn.addEventListener('click', () => { chatOpen ? closeChat() : openChat(); });
    closeChatBtn.addEventListener('click', closeChat);
    chatBackdrop.addEventListener('click', closeChat);
    clearChatBtn.addEventListener('click', () => clearChatModal.show());

    confirmClearChatBtn.addEventListener('click', () => {
        fetch('dashboard.php?ajax_action=clear_chat', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN } })
            .then(ajaxJson)
            .then(data => {
                if (data.status === 'success') { clearChatModal.hide(); fetchChatMessages(); }
                else alert(data.msg || 'Could not clear chat.');
            });
    });

    chatFileInput.addEventListener('change', (e) => {
        if (e.target.files[0]) {
            attachedFile = e.target.files[0];
            fileNameDisplay.textContent = attachedFile.name;
            selectedFilePreview.style.display = 'flex';
        }
    });

    removeFileBtn.addEventListener('click', () => {
        attachedFile = null;
        chatFileInput.value = '';
        selectedFilePreview.style.display = 'none';
    });

    function fetchChatMessages() {
        fetch('dashboard.php?ajax_action=fetch_chat')
            .then(ajaxJson)
            .then(data => {
                if (data.status === 'success') {
                    chatBody.innerHTML = '';
                    if (data.messages.length === 0) {
                        chatBody.innerHTML = '<div class="text-center text-muted small py-3">No messages yet.</div>';
                        return;
                    }
                    data.messages.forEach(m => {
                        const msgDiv = document.createElement('div');
                        msgDiv.className = 'chat-message ' + (m.is_outgoing ? 'outgoing' : 'incoming');
                        let content = `<div>${escapeHtml(m.message)}</div>`;
                        if (m.attachment) {
                            const safeUrl = escapeHtml(m.attachment);
                            const isImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(safeUrl);
                            if (isImg) content += `<a href="${safeUrl}" target="_blank" rel="noopener"><img src="${safeUrl}" alt="Attachment"></a>`;
                            else content += `<a href="${safeUrl}" target="_blank" rel="noopener">📎 Download file</a>`;
                        }
                        msgDiv.innerHTML = content;
                        chatBody.appendChild(msgDiv);
                    });
                    chatBody.scrollTop = chatBody.scrollHeight;
                }
            })
            .catch(err => console.error('Fetch chat error:', err));
    }

    function sendChatMessage() {
        const text = chatInputMessage.value.trim();
        if (text === '' && !attachedFile) return;

        const formData = new FormData();
        formData.append('message', text);
        if (attachedFile) formData.append('attachment', attachedFile);

        chatSendBtn.disabled = true;

        fetch('dashboard.php?ajax_action=send_chat', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: formData
        })
        .then(ajaxJson)
        .then(data => {
            if (data.status === 'success') {
                chatInputMessage.value = '';
                attachedFile = null;
                chatFileInput.value = '';
                selectedFilePreview.style.display = 'none';
                fetchChatMessages();
            } else alert(data.msg || 'Message could not be sent.');
        })
        .catch(err => { console.error('Send chat error:', err); alert('Network error.'); })
        .finally(() => { chatSendBtn.disabled = false; });
    }

    chatSendBtn.addEventListener('click', sendChatMessage);
    chatInputMessage.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
    });

    setInterval(() => { if (chatOpen) fetchChatMessages(); }, 3000);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            document.body.classList.remove('mobile-nav-open');
            document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
        }
        if (chatOpen) {
            if (window.innerWidth >= 576) {
                chatBackdrop.classList.remove('active');
                document.body.style.overflow = '';
            } else {
                chatBackdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
    });

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    /* =========================================================
       ✅ NEW: CHARTS — Chart.js
    ========================================================= */
    (function initCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded');
            return;
        }

        // Global Chart.js defaults
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#64748b';

        /* ---------- 1. STATUS DOUGHNUT ---------- */
        const statusCanvas = document.getElementById('statusDoughnutChart');
        if (statusCanvas) {
            const statusLabels = <?php 
                echo json_encode(array_keys(array_filter($statusChartData, fn($v) => $v > 0))); 
            ?>;
            const statusValues = <?php 
                echo json_encode(array_values(array_filter($statusChartData, fn($v) => $v > 0))); 
            ?>;
            const statusColors = statusLabels.map(l => ({
                'Completed': '#059669',
                'Scheduled': '#2563eb',
                'Ongoing':   '#d97706',
                'Failed':    '#dc2626',
                'Pending':   '#7c3aed'
            }[l] || '#94a3b8'));

            new Chart(statusCanvas, {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusValues,
                        backgroundColor: statusColors,
                        borderColor: '#ffffff',
                        borderWidth: 3,
                        hoverOffset: 8,
                        hoverBorderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    animation: { animateScale: true, animateRotate: true, duration: 900 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleColor: '#fff',
                            bodyColor: '#e2e8f0',
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                label: function(ctx) {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                    return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        /* ---------- 2. MONTHLY TREND BAR ---------- */
        const trendCanvas = document.getElementById('monthlyTrendChart');
        if (trendCanvas) {
            const trendLabels = <?php echo json_encode(array_column($monthlyTrend, 'month_label')); ?>;
            const trendTotal = <?php echo json_encode(array_map('intval', array_column($monthlyTrend, 'total'))); ?>;
            const trendCompleted = <?php echo json_encode(array_map('intval', array_column($monthlyTrend, 'completed'))); ?>;
            const trendFailed = <?php echo json_encode(array_map('intval', array_column($monthlyTrend, 'failed'))); ?>;

            new Chart(trendCanvas, {
                type: 'bar',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Total',
                            data: trendTotal,
                            backgroundColor: 'rgba(203, 213, 225, 0.55)',
                            borderColor: '#cbd5e1',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.7,
                            categoryPercentage: 0.65,
                        },
                        {
                            label: 'Completed',
                            data: trendCompleted,
                            backgroundColor: 'rgba(5, 150, 105, 0.85)',
                            borderColor: '#059669',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.7,
                            categoryPercentage: 0.65,
                        },
                        {
                            label: 'Failed',
                            data: trendFailed,
                            backgroundColor: 'rgba(220, 38, 38, 0.85)',
                            borderColor: '#dc2626',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.7,
                            categoryPercentage: 0.65,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 900, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleColor: '#fff',
                            bodyColor: '#e2e8f0',
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: true,
                            boxPadding: 4,
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: '700' }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0', drawTicks: false },
                            border: { display: false, dash: [4, 4] },
                            ticks: {
                                color: '#64748b',
                                font: { size: 10.5 },
                                precision: 0,
                                padding: 8
                            }
                        }
                    }
                }
            });
        }
    })();
</script>
</body>
</html>