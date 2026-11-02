<?php
include "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

date_default_timezone_set("Asia/Manila");

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$fullname = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = $_SESSION['role'] ?? "Inspector";
$userInitial = strtoupper(substr($fullname, 0, 1));

/* Helper Function for PostgreSQL Table Check */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

$rows = [];
if (tableExists($conn, 'rentals') && tableExists($conn, 'inspections')) {
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            r.id, r.rental_name, r.business_name, r.tracking_no, r.barangay, r.permit_expiry,
            (SELECT COUNT(*) FROM inspections i2 WHERE i2.rental_id = r.id AND i2.inspector_id = :iid1) AS inspection_count,
            (SELECT COUNT(*) FROM inspections i5 WHERE i5.rental_id = r.id AND i5.inspector_id = :iid2 AND i5.inspection_status::text IN ('Completed','Passed')) AS completed_count,
            (SELECT COUNT(*) FROM inspections i6 WHERE i6.rental_id = r.id AND i6.inspector_id = :iid3 AND i6.inspection_status::text = 'Failed') AS failed_count,
            (SELECT MAX(i3.inspection_date) FROM inspections i3 WHERE i3.rental_id = r.id AND i3.inspector_id = :iid4) AS last_inspection_date,
            (SELECT i4.inspection_status::text FROM inspections i4 WHERE i4.rental_id = r.id AND i4.inspector_id = :iid5 ORDER BY i4.inspection_date DESC NULLS LAST LIMIT 1) AS last_inspection_status
        FROM rentals r 
        INNER JOIN inspections i ON i.rental_id = r.id 
        WHERE i.inspector_id = :iid 
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        ORDER BY r.rental_name ASC
    ");
    $stmt->execute([
        ':iid' => $inspectorId,
        ':iid1' => $inspectorId,
        ':iid2' => $inspectorId,
        ':iid3' => $inspectorId,
        ':iid4' => $inspectorId,
        ':iid5' => $inspectorId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pstatus($d) {
    if (!$d) return ['No Record', 'secondary', 0];
    $days = (int) floor((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['Expired', 'danger', $days];
    if ($days <= 30) return ["Expires in {$days}d", 'warning', $days];
    return ['Valid', 'success', $days];
}

/* ============ SUMMARY STATS ============ */
$statTotal    = count($rows);
$statValid    = 0;
$statExpiring = 0;
$statExpired  = 0;
$statNoRecord = 0;

foreach ($rows as $r) {
    $s = pstatus($r['permit_expiry'] ?? null);
    switch ($s[1]) {
        case 'success':   $statValid++;    break;
        case 'warning':   $statExpiring++; break;
        case 'danger':    $statExpired++;  break;
        default:          $statNoRecord++; break;
    }
}

/* ============ BARANGAY LIST ============ */
$barangays = [];
foreach ($rows as $r) {
    $b = trim((string) ($r['barangay'] ?? ''));
    if ($b !== '' && !in_array($b, $barangays, true)) $barangays[] = $b;
}
sort($barangays);

/* ============ EXPIRING SOON (30 days) ============ */
$expiringSoonList = [];
foreach ($rows as $r) {
    $s = pstatus($r['permit_expiry'] ?? null);
    if ($s[1] === 'warning') $expiringSoonList[] = $r;
}

/* ============ UNREAD INSPECTIONS ============ */
$totalAssigned = 0;
$unreadInspectionsCount = 0;
if (tableExists($conn, 'inspections')) {
    $totalStmt = $conn->prepare("SELECT COUNT(*) FROM inspections WHERE inspector_id = :iid");
    $totalStmt->execute([':iid' => $inspectorId]);
    $totalAssigned = (int) $totalStmt->fetchColumn();

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
    } catch (PDOException $e) {}
}

/* ============ JSON FOR JS ============ */
$rowsJson = [];
foreach ($rows as $r) {
    $s = pstatus($r['permit_expiry'] ?? null);
    $rowsJson[] = [
        'id'              => (int) $r['id'],
        'name'            => $r['rental_name'] ?: ($r['business_name'] ?: 'N/A'),
        'business'        => $r['business_name'] ?? '',
        'tracking'        => $r['tracking_no'] ?? '',
        'barangay'        => $r['barangay'] ?? '',
        'permit_expiry'   => $r['permit_expiry'] ?? '',
        'permit_label'    => $s[0],
        'permit_class'    => $s[1],
        'permit_days'     => $s[2],
        'inspection_count'=> (int) ($r['inspection_count'] ?? 0),
        'completed_count' => (int) ($r['completed_count'] ?? 0),
        'failed_count'    => (int) ($r['failed_count'] ?? 0),
        'last_insp_date'  => $r['last_inspection_date'] ?? '',
        'last_insp_status'=> $r['last_inspection_status'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#ffffff">
<title>Properties | RENTRACK LGU</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

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

    /* ============ DARK MODE ============ */
    body.dark-mode {
        --bg-body: #0f172a;
        --bg-card: #1e293b;
        --bg-card-alt: #334155;
        --navbar-bg: #1e293b;
        --text-main: #f1f5f9;
        --text-sub: #cbd5e1;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --border-strong: #475569;
        --red-soft: rgba(220, 38, 38, 0.15);
    }
    body.dark-mode .top-official-bar { background: linear-gradient(90deg, #7f1d1d, #b91c1c 55%, #7f1d1d); }
    body.dark-mode .rt-nav-dropdown,
    body.dark-mode .rt-profile-dropdown,
    body.dark-mode .search-modal-card,
    body.dark-mode .rt-drawer,
    body.dark-mode .notif-dropdown,
    body.dark-mode .chart-card,
    body.dark-mode .modal-content { background: #1e293b !important; color: #f1f5f9 !important; }
    body.dark-mode .rt-table thead th { background: #334155; color: #94a3b8; }
    body.dark-mode .property-mobile-card { background: #1e293b; }
    body.dark-mode .toolbar-input,
    body.dark-mode .toolbar-select { background: #334155; color: #f1f5f9; border-color: #475569; }
    body.dark-mode .rt-table tbody tr:hover { background: rgba(220, 38, 38, 0.15); }
    body.dark-mode .modal-content { background: #1e293b !important; color: #f1f5f9 !important; }
    body.dark-mode .modal-content .modal-body p { color: #cbd5e1 !important; }
    body.dark-mode .btn-cancel { background: #334155 !important; color: #f1f5f9 !important; border-color: #475569 !important; }

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
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .rt-card, .stat-card, .chart-card, .toolbar { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

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
        transition: background-color 0.3s ease;
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

    .rt-topbar-center {
        flex: 1 1 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 20px;
        min-width: 0;
        height: 100%;
    }

    .rt-main-nav { display: flex; align-items: center; gap: 4px; height: 100%; flex-shrink: 0; }

    .rt-nav-link {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 14px; border-radius: 10px;
        color: var(--text-sub); font-size: 13px; font-weight: 600;
        text-decoration: none; transition: var(--transition);
        background: transparent; border: none; font-family: inherit;
        cursor: pointer; white-space: nowrap; height: 42px; position: relative;
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
        position: absolute; top: calc(100% + 4px); left: 0;
        min-width: 250px; background: var(--bg-card);
        border: 1px solid var(--border-color); border-radius: 12px;
        padding: 8px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        opacity: 0; visibility: hidden; transform: translateY(6px);
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
        z-index: 999;
    }
    .rt-nav-group:hover > .rt-nav-dropdown,
    .rt-nav-group.open > .rt-nav-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }

    .rt-nav-dropdown a {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px; border-radius: 8px;
        text-decoration: none; color: var(--text-sub);
        font-size: 13px; font-weight: 500; transition: var(--transition);
    }
    .rt-nav-dropdown a i { width: 18px; text-align: center; font-size: 13px; color: var(--red-accent); flex-shrink: 0; }
    .rt-nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .rt-nav-dropdown a.active { background: var(--red-soft); color: var(--red-accent); font-weight: 700; }

    .rt-dd-badge {
        margin-left: auto; background: #fbbf24; color: #7c2d12;
        font-size: 10px; font-weight: 800; padding: 2px 7px;
        border-radius: 999px; min-width: 20px; text-align: center;
        box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
    }

    .rt-search-trigger {
        position: relative; width: 260px; max-width: 100%; flex-shrink: 0;
        display: flex; align-items: center; gap: 10px;
        padding: 9px 14px 9px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-muted); font-size: 13px;
        cursor: pointer; transition: var(--transition);
        white-space: nowrap; overflow: hidden; height: 42px;
    }
    .rt-search-trigger i.fa-magnifying-glass {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 13px;
    }
    .rt-search-trigger:hover { background: var(--bg-card); border-color: var(--red-accent); color: var(--red-accent); }
    .rt-search-trigger:hover i.fa-magnifying-glass { color: var(--red-accent); }
    .rt-search-trigger .search-placeholder { flex: 1; text-overflow: ellipsis; overflow: hidden; }
    .rt-search-trigger .kbd-hint {
        background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-muted);
        font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 5px;
        letter-spacing: 0.5px; flex-shrink: 0;
    }
    .rt-search-trigger:hover .kbd-hint { border-color: var(--red-accent); color: var(--red-accent); }

    .rt-topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    .icon-btn {
        width: 40px; height: 40px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
        flex-shrink: 0;
    }
    .icon-btn:hover { background: var(--bg-card); border-color: var(--red-accent); color: var(--red-accent); }
    .mobile-search-btn { display: none; }
    .mobile-menu-btn { display: none; }
    .darkmode-btn i { transition: transform 0.4s ease; }
    body.dark-mode .darkmode-btn i { transform: rotate(180deg); }

    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
    }

    .rt-profile-wrapper { position: relative; flex-shrink: 0; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); cursor: pointer; transition: var(--transition);
    }
    .rt-profile-btn:hover { border-color: var(--red-accent); background: var(--bg-card); }
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
        width: 200px; background: var(--bg-card); border: 1px solid var(--border-color);
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

    /* ============ SEARCH MODAL ============ */
    .search-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        z-index: 10000;
        display: flex; align-items: flex-start; justify-content: center;
        padding: 80px 16px 16px;
        opacity: 0; visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .search-modal-overlay.active { opacity: 1; visibility: visible; }

    .search-modal-card {
        width: 100%; max-width: 640px; background: var(--bg-card);
        border-radius: 18px;
        box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transform: translateY(-20px) scale(0.96);
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .search-modal-overlay.active .search-modal-card { transform: translateY(0) scale(1); }

    .search-modal-header {
        display: flex; align-items: center; gap: 12px;
        padding: 16px 20px; border-bottom: 1px solid var(--border-color);
    }
    .search-modal-header .sm-icon {
        width: 40px; height: 40px; border-radius: 12px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 15px; flex-shrink: 0;
        box-shadow: 0 6px 16px rgba(220, 38, 38, 0.25);
    }
    .search-modal-header .sm-input-wrap { flex: 1; position: relative; }
    .search-modal-header input {
        width: 100%; padding: 10px 0; border: none; outline: none;
        font-size: 16px; font-family: inherit; color: var(--text-main);
        background: transparent; font-weight: 500;
    }
    .search-modal-header input::placeholder { color: var(--text-muted); font-weight: 400; }
    .search-modal-header .sm-esc {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--text-muted); font-size: 10.5px; font-weight: 800;
        padding: 5px 9px; border-radius: 6px; cursor: pointer;
        letter-spacing: 0.5px; flex-shrink: 0; transition: var(--transition);
        font-family: inherit;
    }
    .search-modal-header .sm-esc:hover { background: var(--red-soft); border-color: var(--red-accent); color: var(--red-accent); }

    .search-modal-body { max-height: 420px; overflow-y: auto; padding: 8px; }

    .sm-state {
        display: flex; flex-direction: column; align-items: center;
        justify-content: center; padding: 40px 20px; text-align: center;
        color: var(--text-muted); gap: 8px;
    }
    .sm-state .sm-state-icon {
        width: 56px; height: 56px; border-radius: 50%;
        background: var(--bg-card-alt);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: var(--red-accent); margin-bottom: 4px;
    }
    .sm-state .sm-state-title { font-size: 14px; font-weight: 700; color: var(--text-main); }
    .sm-state .sm-state-sub { font-size: 12px; color: var(--text-muted); max-width: 320px; line-height: 1.5; }

    .sm-tips { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-top: 10px; }
    .sm-tips .sm-tip {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--text-sub); font-size: 11px; font-weight: 600;
        padding: 4px 10px; border-radius: 999px;
    }
    .sm-tips .sm-tip i { color: var(--red-accent); font-size: 10px; margin-right: 3px; }

    .sm-result-group-label {
        font-size: 9.5px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--text-muted); padding: 10px 14px 4px;
    }
    .sm-result-item {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 14px; border-radius: 10px;
        text-decoration: none; color: var(--text-main);
        transition: var(--transition); border: 1px solid transparent;
        cursor: pointer;
    }
    .sm-result-item:hover { background: var(--red-soft); border-color: #fecdd3; }
    .sm-result-item .sm-result-icon {
        width: 38px; height: 38px; border-radius: 10px;
        background: var(--bg-card-alt); color: var(--red-accent);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; flex-shrink: 0; transition: var(--transition);
    }
    .sm-result-item:hover .sm-result-icon { background: var(--red-accent); color: #fff; }
    .sm-result-item .sm-result-body { flex: 1; min-width: 0; }
    .sm-result-item .sm-result-type {
        font-size: 9px; font-weight: 800; color: var(--red-accent);
        text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1px;
    }
    .sm-result-item .sm-result-title {
        font-size: 13px; font-weight: 700; color: var(--text-main);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sm-result-item .sm-result-sub {
        font-size: 11px; color: var(--text-muted);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sm-result-item .sm-result-arrow {
        color: var(--text-muted); font-size: 12px; flex-shrink: 0;
        transition: var(--transition);
    }
    .sm-result-item:hover .sm-result-arrow { color: var(--red-accent); transform: translateX(3px); }

    .search-modal-footer {
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; padding: 10px 16px;
        background: var(--bg-card-alt); border-top: 1px solid var(--border-color);
        font-size: 11px; color: var(--text-muted); flex-wrap: wrap;
    }
    .search-modal-footer .sm-hint { display: inline-flex; align-items: center; gap: 6px; }
    .search-modal-footer kbd {
        background: var(--bg-card); border: 1px solid var(--border-color);
        color: var(--text-sub); font-size: 10px; font-weight: 700;
        padding: 2px 6px; border-radius: 4px;
        font-family: 'Space Grotesk', sans-serif; letter-spacing: 0.5px;
    }

    /* ============ CONTENT ============ */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

    .rt-title {
        font-family: 'Space Grotesk', sans-serif;
        color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
        letter-spacing: -0.2px;
        display: flex; align-items: center; gap: 10px;
    }
    .rt-title i { color: var(--red-accent); }
    .rt-page-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }

    /* ============ ALERT BANNER ============ */
    .alert-banner {
        display: flex; align-items: center; gap: 12px;
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 1px solid #f59e0b; border-left: 4px solid #d97706;
        border-radius: 12px; padding: 12px 16px; margin-bottom: 16px;
        animation: fadeUp 0.4s ease;
    }
    body.dark-mode .alert-banner { background: linear-gradient(135deg, #78350f, #92400e); border-color: #b45309; }
    .alert-banner .alert-icon {
        width: 38px; height: 38px; border-radius: 10px;
        background: rgba(217, 119, 6, 0.2); color: #b45309;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; flex-shrink: 0;
    }
    body.dark-mode .alert-banner .alert-icon { background: rgba(254, 243, 199, 0.2); color: #fde68a; }
    .alert-banner .alert-body { flex: 1; min-width: 0; }
    .alert-banner .alert-title { font-size: 13px; font-weight: 800; color: #78350f; }
    body.dark-mode .alert-banner .alert-title { color: #fde68a; }
    .alert-banner .alert-sub { font-size: 11.5px; color: #92400e; margin-top: 1px; }
    body.dark-mode .alert-banner .alert-sub { color: #fef3c7; }
    .alert-banner .alert-cta {
        background: #d97706; color: #fff; padding: 7px 14px; border-radius: 8px;
        font-size: 11.5px; font-weight: 800; text-decoration: none;
        white-space: nowrap; transition: var(--transition); border: none; cursor: pointer;
    }
    .alert-banner .alert-cta:hover { background: #b45309; color: #fff; }

    /* ============ STATS GRID ============ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 14px;
    }
    .stat-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 14px 16px;
        display: flex; align-items: center; justify-content: space-between;
        box-shadow: var(--shadow-soft); transition: var(--transition);
        cursor: pointer; position: relative; overflow: hidden;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lift); }
    .stat-card.active { border-color: var(--red-accent); box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15); }
    .stat-card .stat-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .stat-card .stat-label {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.05em; color: var(--text-muted);
    }
    .stat-card .stat-value {
        font-family: 'Space Grotesk', sans-serif; font-size: 22px;
        font-weight: 800; color: var(--text-main); line-height: 1.15;
    }
    .stat-card .stat-sub {
        font-size: 10.5px; color: var(--text-muted);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .stat-card .stat-icon {
        width: 40px; height: 40px; border-radius: 11px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; flex-shrink: 0;
    }
    .stat-card.s-total .stat-icon { background: rgba(220, 38, 38, 0.12); color: #dc2626; }
    .stat-card.s-valid .stat-icon { background: rgba(5, 150, 105, 0.12); color: #059669; }
    .stat-card.s-warn  .stat-icon { background: rgba(217, 119, 6, 0.14); color: #d97706; }
    .stat-card.s-exp   .stat-icon { background: rgba(185, 28, 28, 0.12); color: #b91c1c; }

    /* ============ CHART CARD ============ */
    .chart-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 16px; margin-bottom: 14px;
        box-shadow: var(--shadow-soft);
        display: grid; grid-template-columns: 1fr 1.6fr; gap: 20px; align-items: center;
    }
    @media (max-width: 900px) { .chart-card { grid-template-columns: 1fr; gap: 12px; } }
    .chart-card .chart-body { position: relative; width: 100%; height: 180px; }
    .chart-card .chart-info h4 {
        font-family: 'Space Grotesk', sans-serif; font-size: 14px;
        font-weight: 800; color: var(--text-main); margin-bottom: 8px;
        display: flex; align-items: center; gap: 8px;
    }
    .chart-card .chart-info h4 i { color: var(--red-accent); }
    .chart-legend-mini {
        display: flex; flex-direction: column; gap: 6px; margin-top: 4px;
    }
    .chart-legend-mini .lg-row {
        display: flex; align-items: center; gap: 8px;
        font-size: 12px; color: var(--text-sub); font-weight: 600;
    }
    .chart-legend-mini .lg-dot {
        width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0;
    }
    .chart-legend-mini .lg-val {
        margin-left: auto; font-weight: 800; color: var(--text-main);
    }

    /* ============ TOOLBAR ============ */
    .toolbar {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 12px; margin-bottom: 14px;
        display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        box-shadow: var(--shadow-soft);
    }
    .toolbar-search {
        flex: 1 1 220px; min-width: 200px; position: relative;
    }
    .toolbar-search i {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 13px; pointer-events: none;
    }
    .toolbar-input {
        width: 100%; padding: 9px 12px 9px 34px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); font-size: 13px; font-family: inherit;
        outline: none; transition: var(--transition); height: 38px;
    }
    .toolbar-input:focus { border-color: var(--red-accent); background: var(--bg-card); }
    .toolbar-select {
        padding: 9px 30px 9px 12px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); font-size: 12.5px; font-family: inherit;
        outline: none; cursor: pointer; transition: var(--transition);
        height: 38px; appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3e%3cpath d='M6 9l6 6 6-6'/%3e%3c/svg%3e");
        background-repeat: no-repeat; background-position: right 8px center; background-size: 14px;
    }
    .toolbar-select:focus { border-color: var(--red-accent); background-color: var(--bg-card); }
    .toolbar-btn {
        padding: 9px 14px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-sub); font-size: 12.5px; font-weight: 700;
        font-family: inherit; cursor: pointer; transition: var(--transition);
        height: 38px; display: inline-flex; align-items: center; gap: 6px;
        white-space: nowrap;
    }
    .toolbar-btn:hover { background: var(--red-soft); border-color: var(--red-accent); color: var(--red-accent); }
    .toolbar-btn.active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }
    .toolbar-btn.active i { color: #fff; }
    .toolbar-btn i { font-size: 12px; }

    /* ============ BULK BAR ============ */
    .bulk-bar {
        display: none; align-items: center; gap: 12px;
        background: linear-gradient(135deg, #1e293b, #0f172a); color: #fff;
        border-radius: 12px; padding: 10px 16px; margin-bottom: 12px;
        animation: fadeUp 0.3s ease;
    }
    .bulk-bar.show { display: flex; }
    .bulk-bar .bulk-info { font-size: 12.5px; font-weight: 700; }
    .bulk-bar .bulk-info strong { color: #fbbf24; }
    .bulk-bar .bulk-actions { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; }
    .bulk-bar .bulk-btn {
        padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2);
        background: rgba(255,255,255,0.1); color: #fff; font-size: 11.5px;
        font-weight: 700; cursor: pointer; transition: var(--transition);
        font-family: inherit; display: inline-flex; align-items: center; gap: 5px;
    }
    .bulk-bar .bulk-btn:hover { background: rgba(255,255,255,0.2); }
    .bulk-bar .bulk-btn.danger { background: rgba(220, 38, 38, 0.5); }
    .bulk-bar .bulk-btn.danger:hover { background: rgba(220, 38, 38, 0.7); }
    .bulk-bar .bulk-close {
        background: transparent; border: none; color: #94a3b8;
        cursor: pointer; font-size: 14px; padding: 4px 8px;
    }

    /* ============ PROPERTIES CARD ============ */
    .rt-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; box-shadow: var(--shadow-soft);
        overflow: hidden; margin-bottom: 16px;
    }
    .rt-card-header {
        background: var(--bg-card-alt); border-bottom: 1px solid var(--border-color);
        padding: 14px 20px; font-weight: 800; color: var(--text-main); font-size: 13.5px;
        display: flex; align-items: center; gap: 8px; justify-content: space-between;
    }
    .rt-card-header .rt-card-title { display: flex; align-items: center; gap: 8px; }
    .rt-card-header i { color: var(--red-accent); }
    .rt-card-header .rt-card-count {
        font-size: 11px; font-weight: 700; color: var(--text-muted);
        background: var(--bg-card); padding: 3px 10px; border-radius: 999px;
        border: 1px solid var(--border-color);
    }
    .rt-card-body { padding: 0; }

    /* ============ TABLE ============ */
    .rt-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .rt-table { width: 100%; border-collapse: collapse; font-size: 13px; color: var(--text-main); margin-bottom: 0; min-width: 820px; }
    .rt-table thead th {
        font-size: 10.5px; text-transform: uppercase;
        color: var(--text-muted); border-bottom: 2px solid var(--border-color);
        padding: 12px 14px; font-weight: 700;
        background: var(--bg-card-alt); white-space: nowrap;
        position: sticky; top: 0; z-index: 1;
    }
    .rt-table tbody td {
        padding: 13px 14px; font-size: 13px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main); vertical-align: middle;
    }
    .rt-table tbody tr:hover { background: var(--red-soft); }
    .rt-table tbody tr:last-child td { border-bottom: none; }
    .rt-table tbody tr.row-pinned { background: rgba(251, 191, 36, 0.08); }
    .rt-table tbody tr.row-highlight { background: var(--red-soft); animation: highlightPulse 1.5s ease; }
    @keyframes highlightPulse {
        0% { background: rgba(220, 38, 38, 0.35); }
        100% { background: var(--red-soft); }
    }

    .rt-checkbox {
        width: 16px; height: 16px; accent-color: var(--red-accent);
        cursor: pointer; vertical-align: middle;
    }

    .fav-btn {
        background: transparent; border: none; cursor: pointer;
        font-size: 15px; padding: 3px; transition: var(--transition);
        line-height: 1; color: var(--text-muted);
    }
    .fav-btn:hover { transform: scale(1.15); }
    .fav-btn.pinned { color: #fbbf24; text-shadow: 0 0 8px rgba(251, 191, 36, 0.6); }

    .row-actions { display: inline-flex; gap: 4px; }
    .action-btn {
        width: 30px; height: 30px; border-radius: 8px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-muted); display: inline-flex; align-items: center;
        justify-content: center; cursor: pointer; font-size: 11.5px;
        transition: var(--transition);
    }
    .action-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); transform: translateY(-1px); }

    /* ============ BARANGAY GROUP HEADER ============ */
    .group-header-row td {
        background: linear-gradient(90deg, var(--bg-card-alt), var(--bg-card));
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 800; font-size: 12px; color: var(--red-accent);
        padding: 10px 14px !important;
        letter-spacing: 0.3px;
        border-bottom: 2px solid var(--red-soft) !important;
    }
    .group-header-row td i { margin-right: 6px; }

    /* ============ PERMIT TIMELINE ============ */
    .permit-timeline { display: flex; flex-direction: column; gap: 4px; min-width: 120px; }
    .permit-timeline-track {
        width: 100%; height: 5px; background: var(--border-color);
        border-radius: 999px; overflow: hidden; position: relative;
    }
    .permit-timeline-fill { height: 100%; border-radius: 999px; transition: width 0.6s ease; }
    .permit-timeline-fill.green { background: linear-gradient(90deg, #10b981, #059669); }
    .permit-timeline-fill.amber { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .permit-timeline-fill.red   { background: linear-gradient(90deg, #ef4444, #b91c1c); }
    .permit-timeline-text { font-size: 10px; color: var(--text-muted); font-weight: 600; }

    /* Mobile card */
    .desktop-table-view { display: none; }
    @media (min-width: 992px) { .desktop-table-view { display: block; width: 100%; } }

    .mobile-card-view { display: block; padding: 12px; }
    @media (min-width: 992px) { .mobile-card-view { display: none; } }

    .property-mobile-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-left: 4px solid var(--red-accent);
        border-radius: 12px; padding: 14px; margin-bottom: 10px;
        box-shadow: var(--shadow-soft); transition: var(--transition);
        position: relative;
    }
    .property-mobile-card:hover { border-color: var(--red-accent); }
    .property-mobile-card:last-child { margin-bottom: 0; }
    .property-mobile-card.row-pinned { border-left-color: #fbbf24; background: rgba(251, 191, 36, 0.06); }
    .property-card-header {
        display: flex; justify-content: space-between; align-items: flex-start;
        margin-bottom: 10px; border-bottom: 1px solid var(--border-color);
        padding-bottom: 8px; gap: 8px;
    }
    .property-card-body {
        display: grid; grid-template-columns: repeat(2, 1fr);
        gap: 8px; font-size: 12px;
    }
    .property-card-field .label {
        color: var(--red-accent); font-size: 10px;
        text-transform: uppercase; font-weight: 700;
        display: block; letter-spacing: 0.3px;
    }
    .property-card-field .value {
        color: var(--text-main); font-weight: 500;
        word-break: break-word; margin-top: 2px;
    }
    .property-card-actions {
        display: flex; gap: 6px; margin-top: 10px;
        padding-top: 10px; border-top: 1px solid var(--border-color);
        flex-wrap: wrap;
    }
    .property-card-actions .pc-btn {
        flex: 1; padding: 8px 6px; border-radius: 8px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-sub); font-size: 11px; font-weight: 700;
        font-family: inherit; cursor: pointer; transition: var(--transition);
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    }
    .property-card-actions .pc-btn:hover { background: var(--red-soft); color: var(--red-accent); border-color: var(--red-accent); }

    /* ============ PAGINATION ============ */
    .pagination-wrap {
        display: flex; align-items: center; justify-content: space-between;
        padding: 12px 20px; border-top: 1px solid var(--border-color);
        background: var(--bg-card-alt); flex-wrap: wrap; gap: 8px;
    }
    .pagination-info { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }
    .pagination-controls { display: flex; gap: 4px; align-items: center; }
    .page-btn {
        min-width: 32px; height: 32px; padding: 0 8px; border-radius: 8px;
        border: 1px solid var(--border-color); background: var(--bg-card);
        color: var(--text-sub); font-size: 12px; font-weight: 700;
        font-family: inherit; cursor: pointer; transition: var(--transition);
        display: inline-flex; align-items: center; justify-content: center;
    }
    .page-btn:hover:not(:disabled) { border-color: var(--red-accent); color: var(--red-accent); }
    .page-btn.active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }
    .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* Badges */
    .badge-custom {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 10px; border-radius: 6px;
        font-size: 10.5px; font-weight: 700;
    }
    .badge-success { background: rgba(16, 185, 129, 0.12); color: #047857; border: 1px solid rgba(16, 185, 129, 0.3); }
    .badge-warning { background: rgba(245, 158, 11, 0.14); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.3); }
    .badge-danger { background: rgba(220, 38, 38, 0.12); color: #b91c1c; border: 1px solid rgba(220, 38, 38, 0.3); }
    .badge-secondary { background: rgba(100, 116, 139, 0.14); color: #475569; border: 1px solid rgba(100, 116, 139, 0.3); }

    /* ============ PROPERTY DETAIL DRAWER ============ */
    .drawer-backdrop {
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        z-index: 10050; opacity: 0; visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .drawer-backdrop.active { opacity: 1; visibility: visible; }

    .rt-drawer {
        position: fixed; top: 0; right: 0;
        width: 460px; max-width: 100vw; height: 100vh; height: 100dvh;
        background: var(--bg-card);
        box-shadow: -20px 0 60px rgba(0, 0, 0, 0.25);
        z-index: 10051;
        transform: translateX(100%);
        transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        display: flex; flex-direction: column;
        overflow: hidden;
    }
    .rt-drawer.active { transform: translateX(0); }

    .rt-drawer-header {
        background: linear-gradient(135deg, #991b1b, #dc2626);
        color: #fff; padding: 18px 20px;
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 12px; flex-shrink: 0;
        padding-top: calc(18px + var(--safe-top));
    }
    .rt-drawer-header .dh-icon {
        width: 44px; height: 44px; border-radius: 12px;
        background: rgba(255, 255, 255, 0.15);
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; flex-shrink: 0;
    }
    .rt-drawer-header .dh-info { flex: 1; min-width: 0; }
    .rt-drawer-header .dh-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 16px; font-weight: 800; line-height: 1.2;
        word-break: break-word;
    }
    .rt-drawer-header .dh-sub { font-size: 11.5px; opacity: 0.9; margin-top: 3px; }
    .rt-drawer-close {
        width: 34px; height: 34px; border-radius: 8px;
        background: rgba(255, 255, 255, 0.15); border: none;
        color: #fff; cursor: pointer; transition: var(--transition);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; flex-shrink: 0;
    }
    .rt-drawer-close:hover { background: rgba(255, 255, 255, 0.3); }

    .rt-drawer-body {
        flex: 1; overflow-y: auto; padding: 18px 20px;
        -webkit-overflow-scrolling: touch;
    }
    .rt-drawer-body::-webkit-scrollbar { width: 6px; }
    .rt-drawer-body::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }

    .drawer-section { margin-bottom: 20px; }
    .drawer-section-title {
        font-size: 10.5px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--text-muted);
        margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
    }
    .drawer-section-title i { color: var(--red-accent); }

    .drawer-field {
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: 12px; padding: 10px 0;
        border-bottom: 1px dashed var(--border-color);
    }
    .drawer-field:last-child { border-bottom: none; }
    .drawer-field .df-label { font-size: 12px; color: var(--text-muted); font-weight: 600; flex-shrink: 0; }
    .drawer-field .df-value { font-size: 12.5px; color: var(--text-main); font-weight: 700; text-align: right; word-break: break-word; }

    .drawer-stats-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
    }
    .drawer-stat {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        border-radius: 10px; padding: 10px; text-align: center;
    }
    .drawer-stat .ds-val {
        font-family: 'Space Grotesk', sans-serif; font-size: 20px;
        font-weight: 800; color: var(--text-main); line-height: 1.1;
    }
    .drawer-stat .ds-lbl {
        font-size: 10px; color: var(--text-muted); font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.03em; margin-top: 3px;
    }
    .drawer-stat.good .ds-val { color: #059669; }
    .drawer-stat.bad  .ds-val { color: #dc2626; }
    .drawer-stat.info .ds-val { color: #2563eb; }

    .drawer-permit-big {
        display: flex; align-items: center; gap: 12px;
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        border-radius: 12px; padding: 14px;
    }
    .drawer-permit-big .dp-icon {
        width: 46px; height: 46px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; flex-shrink: 0;
    }
    .drawer-permit-big .dp-icon.success { background: rgba(5, 150, 105, 0.15); color: #059669; }
    .drawer-permit-big .dp-icon.warning { background: rgba(217, 119, 6, 0.15); color: #d97706; }
    .drawer-permit-big .dp-icon.danger  { background: rgba(220, 38, 38, 0.15); color: #dc2626; }
    .drawer-permit-big .dp-icon.secondary { background: rgba(100, 116, 139, 0.15); color: #64748b; }
    .drawer-permit-big .dp-info { flex: 1; min-width: 0; }
    .drawer-permit-big .dp-label { font-size: 10.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .drawer-permit-big .dp-value { font-size: 15px; font-weight: 800; color: var(--text-main); margin-top: 2px; }

    .drawer-qr {
        display: flex; flex-direction: column; align-items: center;
        gap: 10px; padding: 16px;
        background: var(--bg-card-alt); border: 1px dashed var(--border-color);
        border-radius: 12px;
    }
    .drawer-qr canvas, .drawer-qr img { border-radius: 8px; }
    .drawer-qr .qr-label {
        font-size: 10.5px; color: var(--text-muted); font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.06em; text-align: center;
    }

    .drawer-actions {
        display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
    }
    .drawer-action-btn {
        padding: 11px 12px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-sub); font-size: 12px; font-weight: 700;
        font-family: inherit; cursor: pointer; transition: var(--transition);
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        text-decoration: none;
    }
    .drawer-action-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); transform: translateY(-1px); }
    .drawer-action-btn.primary { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }
    .drawer-action-btn.primary:hover { background: #991b1b; }

    /* ============ FOOTER ============ */
    .footer {
        margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
        border-top: 1px solid var(--border-color); display: flex;
        justify-content: space-between; align-items: center; font-size: 11.5px;
        color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
    }
    .footer strong { color: var(--red-accent); }

    /* Modal */
    .modal-content {
        background: var(--bg-card) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-main) !important; border-radius: 16px !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    }
    .modal-header { border-bottom: 1px solid var(--border-color) !important; padding: 14px 18px; }
    .modal-footer { border-top: 1px solid var(--border-color) !important; padding: 12px 18px; }
    .modal-body { padding: 16px; }
    .modal-title { color: var(--red-accent) !important; }

    /* Empty state */
    .empty-state {
        padding: 40px 20px; text-align: center; color: var(--text-muted);
    }
    .empty-state i { font-size: 28px; opacity: 0.4; color: var(--red-accent); display: block; margin-bottom: 8px; }

    /* Toast */
    .rt-toast {
        position: fixed; bottom: 24px; left: 50%;
        transform: translateX(-50%) translateY(80px);
        background: #0f172a; color: #fff; padding: 12px 20px;
        border-radius: 999px; font-size: 13px; font-weight: 700;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        z-index: 99999; opacity: 0; visibility: hidden;
        transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        display: inline-flex; align-items: center; gap: 8px;
        max-width: calc(100vw - 24px);
    }
    .rt-toast.show { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
    .rt-toast i { color: #10b981; }
    .rt-toast.err i { color: #ef4444; }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 1400px) { .rt-search-trigger { width: 220px; } }
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
            display: none; position: fixed;
            top: var(--header-total-height); left: 0; width: 100%;
            height: auto; max-height: calc(100vh - var(--header-total-height));
            background: var(--navbar-bg); border-bottom: 1px solid var(--border-color);
            flex-direction: column; align-items: stretch;
            padding: 10px 16px 14px; gap: 4px; overflow-y: auto;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); z-index: 1001;
        }
        body.mobile-nav-open .rt-main-nav { display: flex; }
        .rt-nav-link { width: 100%; justify-content: flex-start; padding: 12px 14px; height: auto; font-size: 13.5px; border-radius: 10px; }
        .rt-nav-link.active { box-shadow: none; }
        .rt-nav-group { flex-direction: column; align-items: stretch; width: 100%; height: auto; }
        .rt-nav-group > .rt-nav-link { justify-content: space-between; }
        .rt-nav-dropdown {
            position: static; opacity: 1; visibility: visible; transform: none;
            box-shadow: none; background: var(--bg-card-alt);
            margin: 4px 0 6px 0; border: 1px solid var(--border-color);
            padding: 6px; display: none; border-radius: 10px;
        }
        .rt-nav-group.open > .rt-nav-dropdown { display: block; }
        .rt-nav-group:hover > .rt-nav-dropdown { display: none; }
        .rt-nav-group.open:hover > .rt-nav-dropdown { display: block; }
        .rt-search-trigger { display: none; }
        .mobile-search-btn { display: flex; }
        .rt-topbar-brand { border-right: none; padding-right: 0; }
    }
    @media (max-width: 900px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 768px) {
        .rt-topbar-brand-text strong { font-size: 12px; }
        .rt-topbar-right { gap: 8px; }
        .dashboard-shell { padding: 14px 14px 24px; }
        .rt-title { font-size: 18px; }
        .rt-drawer { width: 100vw; }
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
        .rt-title { font-size: 16px; }
        .footer { font-size: 10px; flex-direction: column; text-align: center; }
        .search-modal-overlay { padding: 60px 10px 10px; }
        .search-modal-card { border-radius: 14px; }
        .search-modal-header { padding: 12px 14px; gap: 10px; }
        .search-modal-header .sm-icon { width: 34px; height: 34px; font-size: 13px; }
        .search-modal-header input { font-size: 15px; }
        .stats-grid { gap: 8px; }
        .stat-card { padding: 10px 12px; }
        .stat-card .stat-value { font-size: 18px; }
        .stat-card .stat-icon { width: 34px; height: 34px; font-size: 14px; }
        .toolbar { padding: 10px; }
        .toolbar-search { flex: 1 1 100%; }
        .toolbar-select { flex: 1; min-width: 0; }
    }
    @media (max-width: 380px) {
        .stats-grid { grid-template-columns: 1fr; }
    }
    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .search-modal-header input { font-size: 16px !important; }
    }
    @media print {
        .top-official-bar, .rt-topbar, .footer, .toolbar, .bulk-bar,
        .alert-banner, .search-modal-overlay, .drawer-backdrop, .rt-drawer,
        .row-actions, .fav-btn, .rt-checkbox, .pagination-wrap { display: none !important; }
        body { padding-top: 0; background: #fff; }
        .rt-card { box-shadow: none; border: 1px solid #ccc; }
    }
</style>
</head>
<body>

<!-- TOP OFFICIAL BAR -->
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

<!-- MERGED HEADER -->
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
            <a href="dashboard.php" class="rt-nav-link">
                <i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span>
            </a>

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

            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn has-active">
                    <i class="fa-solid fa-file-lines"></i> <span>Reports</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="properties.php" class="active"><i class="fa-solid fa-building"></i> Properties</a>
                    <a href="reports.php"><i class="fa-solid fa-file-lines"></i> Reports</a>
                </div>
            </div>

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
            <span class="search-placeholder">Search properties...</span>
            <span class="kbd-hint">Ctrl K</span>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <button class="icon-btn darkmode-btn" id="darkmodeToggle" title="Toggle dark mode">
            <i class="fa-solid fa-moon"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
            </button>
            <div id="notifDropdown" class="notif-dropdown" style="display:none; position:absolute; top:48px; right:0; width:300px; max-width:calc(100vw - 20px); background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">Notifications</span>
                </div>
                <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">
                    <i class="fa-regular fa-circle-check me-1"></i> You're all caught up.
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo $fullname; ?></span>
                    <span class="role"><?php echo htmlspecialchars($staffRole, ENT_QUOTES, 'UTF-8'); ?></span>
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

<!-- SEARCH MODAL -->
<div class="search-modal-overlay" id="searchModalOverlay">
    <div class="search-modal-card" role="dialog" aria-modal="true">
        <div class="search-modal-header">
            <div class="sm-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="sm-input-wrap">
                <input type="text" id="searchModalInput" placeholder="Search properties by name, tracking, barangay..." autocomplete="off" spellcheck="false">
            </div>
            <button type="button" class="sm-esc" id="searchModalClose">ESC</button>
        </div>

        <div class="search-modal-body" id="searchModalBody">
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Properties</div>
                <div class="sm-state-sub">Filter assigned properties by name, business, tracking number, or barangay.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Property name</span>
                    <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking no.</span>
                    <span class="sm-tip"><i class="fa-solid fa-location-dot"></i> Barangay</span>
                </div>
            </div>
        </div>

        <div class="search-modal-footer">
            <span class="sm-hint"><kbd>Enter</kbd> Open first result</span>
            <span class="sm-hint"><kbd>ESC</kbd> Close</span>
        </div>
    </div>
</div>

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <!-- PAGE HEADER -->
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div>
                    <h2 class="rt-title"><i class="fa-solid fa-building"></i> Property Directory</h2>
                    <p class="rt-page-sub">Assigned LGU properties with permit compliance overview.</p>
                </div>
                <span style="display:inline-flex; align-items:center; gap:7px; background:rgba(5,150,105,0.1); border:1px solid rgba(5,150,105,0.2); color:var(--emerald-accent); font-size:11px; font-weight:700; padding:6px 12px; border-radius:999px;">
                    <span style="width:7px; height:7px; border-radius:50%; background:var(--emerald-accent); box-shadow:0 0 8px var(--emerald-accent);"></span>
                    <?php echo count($rows); ?> properties
                </span>
            </div>

            <!-- 🔔 EXPIRING SOON ALERT -->
            <?php if (count($expiringSoonList) > 0): ?>
            <div class="alert-banner" id="alertBanner">
                <div class="alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="alert-body">
                    <div class="alert-title">
                        <?php echo count($expiringSoonList); ?> <?php echo count($expiringSoonList) === 1 ? 'property has a permit' : 'properties have permits'; ?> expiring within 30 days
                    </div>
                    <div class="alert-sub">Review and coordinate renewals before expiration.</div>
                </div>
                <button class="alert-cta" id="alertCtaBtn">
                    <i class="fa-solid fa-eye me-1"></i> View
                </button>
            </div>
            <?php endif; ?>

            <!-- 📊 STATS CARDS -->
            <div class="stats-grid">
                <div class="stat-card s-total" data-stat-filter="all">
                    <div class="stat-body">
                        <span class="stat-label">Total Properties</span>
                        <div class="stat-value"><?php echo $statTotal; ?></div>
                        <span class="stat-sub">Assigned to you</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-building"></i></div>
                </div>

                <div class="stat-card s-valid" data-stat-filter="valid">
                    <div class="stat-body">
                        <span class="stat-label">Valid Permits</span>
                        <div class="stat-value"><?php echo $statValid; ?></div>
                        <span class="stat-sub">Compliant</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                </div>

                <div class="stat-card s-warn" data-stat-filter="warning">
                    <div class="stat-body">
                        <span class="stat-label">Expiring Soon</span>
                        <div class="stat-value"><?php echo $statExpiring; ?></div>
                        <span class="stat-sub">Within 30 days</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                </div>

                <div class="stat-card s-exp" data-stat-filter="danger">
                    <div class="stat-body">
                        <span class="stat-label">Expired</span>
                        <div class="stat-value"><?php echo $statExpired + $statNoRecord; ?></div>
                        <span class="stat-sub">Needs action</span>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                </div>
            </div>

            <!-- 📈 COMPLIANCE CHART -->
            <div class="chart-card">
                <div class="chart-info">
                    <h4><i class="fa-solid fa-chart-pie"></i> Compliance Breakdown</h4>
                    <div class="chart-legend-mini">
                        <div class="lg-row">
                            <span class="lg-dot" style="background:#059669;"></span> Valid
                            <span class="lg-val"><?php echo $statValid; ?></span>
                        </div>
                        <div class="lg-row">
                            <span class="lg-dot" style="background:#d97706;"></span> Expiring
                            <span class="lg-val"><?php echo $statExpiring; ?></span>
                        </div>
                        <div class="lg-row">
                            <span class="lg-dot" style="background:#dc2626;"></span> Expired
                            <span class="lg-val"><?php echo $statExpired; ?></span>
                        </div>
                        <div class="lg-row">
                            <span class="lg-dot" style="background:#94a3b8;"></span> No Record
                            <span class="lg-val"><?php echo $statNoRecord; ?></span>
                        </div>
                    </div>
                </div>
                <div class="chart-body">
                    <?php if ($statTotal > 0): ?>
                        <canvas id="complianceChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state" style="padding:20px;">
                            <i class="fa-regular fa-chart-bar"></i>
                            <span style="font-size:12px;">No data to display</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 🛠️ TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="toolbar-input" id="filterSearch" placeholder="Search name, tracking, barangay...">
                </div>

                <select class="toolbar-select" id="filterBarangay">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?php echo htmlspecialchars($b, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($b, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>

                <select class="toolbar-select" id="filterStatus">
                    <option value="">All Statuses</option>
                    <option value="success">Valid</option>
                    <option value="warning">Expiring Soon</option>
                    <option value="danger">Expired</option>
                    <option value="secondary">No Record</option>
                </select>

                <select class="toolbar-select" id="sortBy">
                    <option value="name-asc">Name (A-Z)</option>
                    <option value="name-desc">Name (Z-A)</option>
                    <option value="expiry-asc">Expiry (Soonest)</option>
                    <option value="expiry-desc">Expiry (Latest)</option>
                    <option value="barangay-asc">Barangay (A-Z)</option>
                </select>

                <button class="toolbar-btn" id="groupToggle" title="Group by Barangay">
                    <i class="fa-solid fa-layer-group"></i> Group
                </button>

                <button class="toolbar-btn" id="exportCsvBtn" title="Export CSV">
                    <i class="fa-solid fa-file-csv"></i> CSV
                </button>

                <button class="toolbar-btn" id="printBtn" title="Print">
                    <i class="fa-solid fa-print"></i>
                </button>

                <button class="toolbar-btn" id="resetFiltersBtn" title="Reset Filters">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </div>

            <!-- ✅ BULK ACTION BAR -->
            <div class="bulk-bar" id="bulkBar">
                <span class="bulk-info"><strong id="bulkCount">0</strong> selected</span>
                <div class="bulk-actions">
                    <button class="bulk-btn" id="bulkExportBtn"><i class="fa-solid fa-file-csv"></i> Export</button>
                    <button class="bulk-btn" id="bulkPinBtn"><i class="fa-solid fa-star"></i> Pin</button>
                    <button class="bulk-btn" id="bulkClearBtn"><i class="fa-solid fa-xmark"></i> Clear</button>
                </div>
                <button class="bulk-close" id="bulkCloseBtn"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- PROPERTIES CARD -->
            <div class="rt-card">
                <div class="rt-card-header">
                    <div class="rt-card-title"><i class="fa-solid fa-list"></i> <span id="cardTitleText">Assigned Properties</span></div>
                    <span class="rt-card-count" id="cardCountText"><?php echo count($rows); ?> items</span>
                </div>
                <div class="rt-card-body">
                    <?php if(!$rows): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <span style="font-size:12.5px;">No assigned properties found.</span>
                        </div>
                    <?php else: ?>

                        <!-- DESKTOP TABLE VIEW -->
                        <div class="desktop-table-view">
                            <div class="rt-table-wrap">
                                <table class="rt-table" id="propertiesTable">
                                    <thead>
                                        <tr>
                                            <th style="width:36px;"><input type="checkbox" class="rt-checkbox" id="selectAllCheckbox"></th>
                                            <th style="width:36px;"><i class="fa-solid fa-star" style="color:#fbbf24;"></i></th>
                                            <th>Property</th>
                                            <th>Tracking No.</th>
                                            <th>Barangay</th>
                                            <th>Permit Expiry</th>
                                            <th>Compliance</th>
                                            <th style="width:120px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="propertiesTbody">
                                        <?php foreach($rows as $r):
                                            [$lab, $cls, $days] = pstatus($r['permit_expiry'] ?? null);
                                            $badgeClass = 'badge-' . $cls;
                                            $searchBlob = strtolower(($r['rental_name'] ?? '') . ' ' . ($r['business_name'] ?? '') . ' ' . ($r['tracking_no'] ?? '') . ' ' . ($r['barangay'] ?? ''));
                                            // Timeline percentage
                                            if ($cls === 'success') $pct = max(20, min(100, ($days / 365) * 100));
                                            elseif ($cls === 'warning') $pct = max(8, ($days / 30) * 20);
                                            elseif ($cls === 'danger') $pct = 100;
                                            else $pct = 0;
                                            $tlClass = $cls === 'success' ? 'green' : ($cls === 'warning' ? 'amber' : 'red');
                                        ?>
                                        <tr
                                            data-id="<?php echo (int) $r['id']; ?>"
                                            data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-barangay="<?php echo htmlspecialchars($r['barangay'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo $cls; ?>"
                                            data-name="<?php echo htmlspecialchars($r['rental_name'] ?? $r['business_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-expiry="<?php echo htmlspecialchars($r['permit_expiry'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <td><input type="checkbox" class="rt-checkbox row-checkbox" data-id="<?php echo (int) $r['id']; ?>"></td>
                                            <td><button class="fav-btn" data-id="<?php echo (int) $r['id']; ?>" title="Pin"><i class="fa-regular fa-star"></i></button></td>
                                            <td>
                                                <strong style="color:var(--text-main);"><?php echo htmlspecialchars($r['rental_name'] ?? $r['business_name'] ?? 'N/A'); ?></strong>
                                                <?php if(!empty($r['business_name']) && ($r['business_name'] !== ($r['rental_name'] ?? ''))): ?>
                                                    <br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($r['business_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--red-accent); font-weight:700;"><?php echo htmlspecialchars($r['tracking_no'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($r['barangay'] ?? '—'); ?></td>
                                            <td>
                                                <div class="permit-timeline">
                                                    <div style="font-size:12px; font-weight:700;"><?php echo !empty($r['permit_expiry']) ? date('M d, Y', strtotime($r['permit_expiry'])) : '—'; ?></div>
                                                    <?php if ($cls !== 'secondary'): ?>
                                                    <div class="permit-timeline-track">
                                                        <div class="permit-timeline-fill <?php echo $tlClass; ?>" style="width:<?php echo (int)$pct; ?>%;"></div>
                                                    </div>
                                                    <div class="permit-timeline-text">
                                                        <?php
                                                        if ($cls === 'success') echo $days . ' days left';
                                                        elseif ($cls === 'warning') echo $days . ' days left ⚠️';
                                                        else echo abs($days) . ' days ago';
                                                        ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><span class="badge-custom <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($lab); ?></span></td>
                                            <td>
                                                <div class="row-actions">
                                                    <button class="action-btn view-btn" data-id="<?php echo (int) $r['id']; ?>" title="View Details"><i class="fa-solid fa-eye"></i></button>
                                                    <button class="action-btn qr-btn" data-id="<?php echo (int) $r['id']; ?>" title="QR Code"><i class="fa-solid fa-qrcode"></i></button>
                                                    <button class="action-btn chat-btn" data-id="<?php echo (int) $r['id']; ?>" title="Message Owner"><i class="fa-solid fa-comment"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- MOBILE CARD VIEW -->
                        <div class="mobile-card-view" id="mobileCardView">
                            <?php foreach($rows as $r):
                                [$lab, $cls, $days] = pstatus($r['permit_expiry'] ?? null);
                                $badgeClass = 'badge-' . $cls;
                                $searchBlob = strtolower(($r['rental_name'] ?? '') . ' ' . ($r['business_name'] ?? '') . ' ' . ($r['tracking_no'] ?? '') . ' ' . ($r['barangay'] ?? ''));
                            ?>
                            <div class="property-mobile-card"
                                 data-id="<?php echo (int) $r['id']; ?>"
                                 data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                                 data-barangay="<?php echo htmlspecialchars($r['barangay'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                 data-status="<?php echo $cls; ?>"
                                 data-name="<?php echo htmlspecialchars($r['rental_name'] ?? $r['business_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                 data-expiry="<?php echo htmlspecialchars($r['permit_expiry'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="property-card-header">
                                    <div style="min-width:0; flex:1;">
                                        <strong style="font-size:13.5px; color:var(--text-main); word-break:break-word;">
                                            <?php echo htmlspecialchars($r['rental_name'] ?? $r['business_name'] ?? 'N/A'); ?>
                                        </strong>
                                        <?php if(!empty($r['business_name']) && ($r['business_name'] !== ($r['rental_name'] ?? ''))): ?>
                                            <br><small style="color:var(--text-muted); font-size:11px;"><?php echo htmlspecialchars($r['business_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex-shrink:0; display:flex; align-items:center; gap:6px;">
                                        <button class="fav-btn" data-id="<?php echo (int) $r['id']; ?>"><i class="fa-regular fa-star"></i></button>
                                        <span class="badge-custom <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($lab); ?></span>
                                    </div>
                                </div>
                                <div class="property-card-body">
                                    <div class="property-card-field">
                                        <span class="label">Tracking No.</span>
                                        <span class="value" style="color:var(--red-accent); font-weight:700;"><?php echo htmlspecialchars($r['tracking_no'] ?? '—'); ?></span>
                                    </div>
                                    <div class="property-card-field">
                                        <span class="label">Barangay</span>
                                        <span class="value"><?php echo htmlspecialchars($r['barangay'] ?? '—'); ?></span>
                                    </div>
                                    <div class="property-card-field" style="grid-column: span 2;">
                                        <span class="label">Permit Expiry</span>
                                        <span class="value"><?php echo !empty($r['permit_expiry']) ? date('M d, Y', strtotime($r['permit_expiry'])) : '—'; ?></span>
                                    </div>
                                </div>
                                <div class="property-card-actions">
                                    <button class="pc-btn view-btn" data-id="<?php echo (int) $r['id']; ?>"><i class="fa-solid fa-eye"></i> View</button>
                                    <button class="pc-btn qr-btn" data-id="<?php echo (int) $r['id']; ?>"><i class="fa-solid fa-qrcode"></i> QR</button>
                                    <button class="pc-btn chat-btn" data-id="<?php echo (int) $r['id']; ?>"><i class="fa-solid fa-comment"></i> Chat</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </div>

                <!-- PAGINATION -->
                <div class="pagination-wrap" id="paginationWrap" style="display:none;">
                    <div class="pagination-info" id="paginationInfo">Showing 0 of 0</div>
                    <div class="pagination-controls" id="paginationControls"></div>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong>RENTRACK LGU</strong> &middot; Inspector Portal</span>
                <span>Unified Rental Registration &amp; Compliance System</span>
            </footer>
        </div>
    </div>
</div>

<!-- PROPERTY DETAIL DRAWER -->
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<aside class="rt-drawer" id="rtDrawer" aria-hidden="true">
    <div class="rt-drawer-header">
        <div class="dh-icon"><i class="fa-solid fa-building"></i></div>
        <div class="dh-info">
            <div class="dh-title" id="drawerTitle">Property Name</div>
            <div class="dh-sub" id="drawerSub">—</div>
        </div>
        <button class="rt-drawer-close" id="drawerCloseBtn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="rt-drawer-body">
        <!-- Permit status -->
        <div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-id-card"></i> Permit Status</div>
            <div class="drawer-permit-big">
                <div class="dp-icon" id="drawerPermitIcon"><i class="fa-solid fa-circle-check"></i></div>
                <div class="dp-info">
                    <div class="dp-label">Expiry</div>
                    <div class="dp-value" id="drawerPermitValue">—</div>
                    <div class="permit-timeline" style="margin-top:6px;">
                        <div class="permit-timeline-track">
                            <div class="permit-timeline-fill" id="drawerPermitFill" style="width:0%;"></div>
                        </div>
                        <div class="permit-timeline-text" id="drawerPermitText">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Basic info -->
        <div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-info-circle"></i> Property Details</div>
            <div class="drawer-field"><span class="df-label">Business Name</span><span class="df-value" id="drawerBusiness">—</span></div>
            <div class="drawer-field"><span class="df-label">Tracking No.</span><span class="df-value" id="drawerTracking" style="color:var(--red-accent);">—</span></div>
            <div class="drawer-field"><span class="df-label">Barangay</span><span class="df-value" id="drawerBarangay">—</span></div>
        </div>

        <!-- Inspection summary -->
        <div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-clipboard-check"></i> Inspection Summary</div>
            <div class="drawer-stats-grid">
                <div class="drawer-stat info">
                    <div class="ds-val" id="drawerInspTotal">0</div>
                    <div class="ds-lbl">Total</div>
                </div>
                <div class="drawer-stat good">
                    <div class="ds-val" id="drawerInspCompleted">0</div>
                    <div class="ds-lbl">Completed</div>
                </div>
                <div class="drawer-stat bad">
                    <div class="ds-val" id="drawerInspFailed">0</div>
                    <div class="ds-lbl">Failed</div>
                </div>
            </div>
            <div class="drawer-field" style="margin-top:8px;">
                <span class="df-label">Last Inspection</span>
                <span class="df-value" id="drawerLastInsp">—</span>
            </div>
            <div class="drawer-field">
                <span class="df-label">Last Status</span>
                <span class="df-value" id="drawerLastStatus">—</span>
            </div>
        </div>

        <!-- QR Code -->
        <div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-qrcode"></i> Tracking QR Code</div>
            <div class="drawer-qr">
                <div id="drawerQrWrap"></div>
                <div class="qr-label">Scan to open property record</div>
            </div>
        </div>

        <!-- Actions -->
        <div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div>
            <div class="drawer-actions">
                <a href="inspections.php" class="drawer-action-btn primary"><i class="fa-solid fa-calendar-plus"></i> Schedule</a>
                <a href="inspections.php" class="drawer-action-btn"><i class="fa-solid fa-clipboard-list"></i> Full Details</a>
                <button class="drawer-action-btn" id="drawerChatBtn"><i class="fa-solid fa-comment"></i> Message Owner</button>
                <button class="drawer-action-btn" id="drawerCopyTracking"><i class="fa-solid fa-copy"></i> Copy Tracking</button>
            </div>
        </div>
    </div>
</aside>

<!-- TOAST -->
<div class="rt-toast" id="rtToast"><i class="fa-solid fa-circle-check"></i> <span id="rtToastText">Done</span></div>

<!-- LOGOUT MODAL -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="font-size:15px;"><i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="small mb-0" style="color:#475569;">Are you sure you want to log out from your session?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn px-4 py-2 btn-cancel" data-bs-dismiss="modal" style="font-size:12px; background:#f1f5f9; border:1px solid #e2e8f0; color:#0f172a;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn px-4 py-2" style="font-size:12px; background:#dc2626; border:none; color:#fff;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script>
/* =========================================================
   DATA
========================================================= */
const PROPERTIES = <?php echo json_encode($rowsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PROPERTY_MAP = Object.fromEntries(PROPERTIES.map(p => [p.id, p]));

/* =========================================================
   UTILITIES
========================================================= */
function toast(text, isError) {
    const t = document.getElementById('rtToast');
    const txt = document.getElementById('rtToastText');
    const icon = t.querySelector('i');
    txt.textContent = text;
    t.classList.toggle('err', !!isError);
    icon.className = isError ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-circle-check';
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 2200);
}
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    if (isNaN(dt)) return '—';
    return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

/* =========================================================
   NAVIGATION
========================================================= */
document.getElementById('mobileMenuBtn')?.addEventListener('click', (e) => {
    e.stopPropagation();
    document.body.classList.toggle('mobile-nav-open');
});
document.querySelectorAll('.rt-nav-group > .rt-nav-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault(); e.stopPropagation();
        const group = btn.parentElement;
        document.querySelectorAll('.rt-nav-group').forEach(g => { if (g !== group) g.classList.remove('open'); });
        group.classList.toggle('open');
    });
});
const profileToggle = document.getElementById('profileToggle');
const profileDropdown = document.getElementById('profileDropdown');
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
profileToggle?.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
notifBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', (e) => {
    const nav = document.getElementById('rtMainNav');
    if (!nav || !nav.contains(e.target)) {
        document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
        if (!e.target.closest('#mobileMenuBtn')) document.body.classList.remove('mobile-nav-open');
    }
    if (profileDropdown) profileDropdown.classList.remove('show');
    if (notifDropdown) notifDropdown.style.display = 'none';
});
document.querySelectorAll('.rt-nav-dropdown a, .rt-nav-link:not(.rt-nav-btn)').forEach(link => {
    link.addEventListener('click', () => document.body.classList.remove('mobile-nav-open'));
});
document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
    e.preventDefault();
    new bootstrap.Modal(document.getElementById('logoutModal')).show();
});

/* =========================================================
   DARK MODE
========================================================= */
(function initDarkMode() {
    const saved = localStorage.getItem('rentrack_dark_mode');
    if (saved === '1') document.body.classList.add('dark-mode');
    document.getElementById('darkmodeToggle')?.addEventListener('click', () => {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('rentrack_dark_mode', isDark ? '1' : '0');
        // update chart colors if exists
        if (window.__complianceChart) {
            window.__complianceChart.options.plugins.legend.labels.color = isDark ? '#cbd5e1' : '#334155';
            window.__complianceChart.update();
        }
        toast(isDark ? '🌙 Dark mode on' : '☀️ Light mode on');
    });
})();

/* =========================================================
   SEARCH MODAL
========================================================= */
const searchOverlay = document.getElementById('searchModalOverlay');
const searchInput = document.getElementById('searchModalInput');
const searchBody = document.getElementById('searchModalBody');
const openSearchBtn = document.getElementById('openSearchBtn');
const searchModalCloseBtn = document.getElementById('searchModalClose');

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
            <div class="sm-state-title">Search Properties</div>
            <div class="sm-state-sub">Filter assigned properties by name, business, tracking number, or barangay.</div>
            <div class="sm-tips">
                <span class="sm-tip"><i class="fa-solid fa-building"></i> Property name</span>
                <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking no.</span>
                <span class="sm-tip"><i class="fa-solid fa-location-dot"></i> Barangay</span>
            </div>
        </div>
    `;
}
openSearchBtn?.addEventListener('click', openSearchModal);
openSearchBtn?.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openSearchModal(); } });
document.getElementById('mobileSearchBtn')?.addEventListener('click', openSearchModal);
searchModalCloseBtn?.addEventListener('click', closeSearchModal);
searchOverlay?.addEventListener('click', (e) => { if (e.target === searchOverlay) closeSearchModal(); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && searchOverlay.classList.contains('active')) { closeSearchModal(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openSearchModal(); }
});

searchInput?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    if (q.length < 2) { resetSearchBody(); return; }
    const matches = PROPERTIES.filter(p => {
        const blob = (p.name + ' ' + p.business + ' ' + p.tracking + ' ' + p.barangay).toLowerCase();
        return blob.includes(q);
    });
    searchBody.innerHTML = '';
    if (matches.length === 0) {
        searchBody.innerHTML = `
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">No results found</div>
                <div class="sm-state-sub">No properties matched "<strong>${escapeHtml(q)}</strong>".</div>
            </div>`;
        return;
    }
    const label = document.createElement('div');
    label.className = 'sm-result-group-label';
    label.textContent = 'Properties · ' + matches.length;
    searchBody.appendChild(label);
    matches.slice(0, 10).forEach(p => {
        const a = document.createElement('a');
        a.className = 'sm-result-item';
        a.innerHTML = `
            <div class="sm-result-icon"><i class="fa-solid fa-building"></i></div>
            <div class="sm-result-body">
                <div class="sm-result-type">Property</div>
                <div class="sm-result-title">${escapeHtml(p.name)}</div>
                <div class="sm-result-sub">${p.tracking ? 'Track: ' + escapeHtml(p.tracking) : ''}${p.tracking && p.barangay ? ' · ' : ''}${p.barangay ? escapeHtml(p.barangay) : ''}</div>
            </div>
            <i class="fa-solid fa-arrow-right sm-result-arrow"></i>`;
        a.addEventListener('click', () => {
            closeSearchModal();
            openDrawer(p.id);
        });
        searchBody.appendChild(a);
    });
});

/* =========================================================
   FILTERS + SORT + PAGINATION + GROUPING
========================================================= */
const filterSearch   = document.getElementById('filterSearch');
const filterBarangay = document.getElementById('filterBarangay');
const filterStatus   = document.getElementById('filterStatus');
const sortBy         = document.getElementById('sortBy');
const groupToggle    = document.getElementById('groupToggle');
const resetFiltersBtn= document.getElementById('resetFiltersBtn');
const propertiesTbody= document.getElementById('propertiesTbody');
const mobileCardView = document.getElementById('mobileCardView');
const paginationWrap = document.getElementById('paginationWrap');
const paginationInfo = document.getElementById('paginationInfo');
const paginationControls = document.getElementById('paginationControls');
const cardCountText  = document.getElementById('cardCountText');
const cardTitleText  = document.getElementById('cardTitleText');

let currentPage = 1;
const PER_PAGE = 10;
let isGrouped = false;
let favorites = new Set(JSON.parse(localStorage.getItem('rentrack_favs') || '[]'));

function getFilteredRows() {
    const q = (filterSearch?.value || '').trim().toLowerCase();
    const brgy = filterBarangay?.value || '';
    const status = filterStatus?.value || '';
    const sort = sortBy?.value || 'name-asc';

    let list = PROPERTIES.slice();

    if (q) list = list.filter(p => (p.name + ' ' + p.business + ' ' + p.tracking + ' ' + p.barangay).toLowerCase().includes(q));
    if (brgy) list = list.filter(p => p.barangay === brgy);
    if (status) list = list.filter(p => p.permit_class === status);

    list.sort((a, b) => {
        switch (sort) {
            case 'name-asc':    return a.name.localeCompare(b.name);
            case 'name-desc':   return b.name.localeCompare(a.name);
            case 'barangay-asc':return (a.barangay || '').localeCompare(b.barangay || '');
            case 'expiry-asc': {
                const av = a.permit_expiry ? new Date(a.permit_expiry).getTime() : Infinity;
                const bv = b.permit_expiry ? new Date(b.permit_expiry).getTime() : Infinity;
                return av - bv;
            }
            case 'expiry-desc': {
                const av = a.permit_expiry ? new Date(a.permit_expiry).getTime() : 0;
                const bv = b.permit_expiry ? new Date(b.permit_expiry).getTime() : 0;
                return bv - av;
            }
        }
        return 0;
    });

    // Pinned items float to top
    list.sort((a, b) => {
        const af = favorites.has(a.id) ? 0 : 1;
        const bf = favorites.has(b.id) ? 0 : 1;
        return af - bf;
    });

    return list;
}

function renderTable() {
    const list = getFilteredRows();
    const total = list.length;

    cardCountText.textContent = total + ' item' + (total === 1 ? '' : 's');
    cardTitleText.textContent = isGrouped ? 'Grouped by Barangay' : 'Assigned Properties';

    if (total === 0) {
        propertiesTbody.innerHTML = '';
        mobileCardView.innerHTML = `<div class="empty-state"><i class="fa-regular fa-folder-open"></i><span style="font-size:12.5px;">No properties match the current filters.</span></div>`;
        paginationWrap.style.display = 'none';
        return;
    }

    // Pagination
    const start = (currentPage - 1) * PER_PAGE;
    const pageItems = isGrouped ? list : list.slice(start, start + PER_PAGE);
    const totalPages = isGrouped ? 1 : Math.ceil(total / PER_PAGE);

    // Desktop table
    propertiesTbody.innerHTML = '';
    if (isGrouped) {
        const groups = {};
        pageItems.forEach(p => {
            const key = p.barangay || 'Unassigned';
            if (!groups[key]) groups[key] = [];
            groups[key].push(p);
        });
        Object.keys(groups).sort().forEach(brgy => {
            const gh = document.createElement('tr');
            gh.className = 'group-header-row';
            gh.innerHTML = `<td colspan="8"><i class="fa-solid fa-location-dot"></i> ${escapeHtml(brgy)} <span style="opacity:.6; font-weight:600;">(${groups[brgy].length})</span></td>`;
            propertiesTbody.appendChild(gh);
            groups[brgy].forEach(p => propertiesTbody.appendChild(buildRow(p)));
        });
    } else {
        pageItems.forEach(p => propertiesTbody.appendChild(buildRow(p)));
    }

    // Mobile cards
    mobileCardView.innerHTML = '';
    pageItems.forEach(p => mobileCardView.appendChild(buildMobileCard(p)));

    // Pagination UI
    if (isGrouped || totalPages <= 1) {
        paginationWrap.style.display = 'none';
    } else {
        paginationWrap.style.display = 'flex';
        paginationInfo.textContent = `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} of ${total}`;
        paginationControls.innerHTML = '';
        const prevBtn = document.createElement('button');
        prevBtn.className = 'page-btn';
        prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        prevBtn.disabled = currentPage === 1;
        prevBtn.addEventListener('click', () => { currentPage--; renderTable(); });
        paginationControls.appendChild(prevBtn);

        const maxButtons = 5;
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) startPage = Math.max(1, endPage - maxButtons + 1);

        for (let i = startPage; i <= endPage; i++) {
            const b = document.createElement('button');
            b.className = 'page-btn' + (i === currentPage ? ' active' : '');
            b.textContent = i;
            b.addEventListener('click', () => { currentPage = i; renderTable(); });
            paginationControls.appendChild(b);
        }

        const nextBtn = document.createElement('button');
        nextBtn.className = 'page-btn';
        nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.addEventListener('click', () => { currentPage++; renderTable(); });
        paginationControls.appendChild(nextBtn);
    }

    bindRowActions();
    updateBulkBar();
}

function buildRow(p) {
    const tr = document.createElement('tr');
    tr.dataset.id = p.id;
    const isFav = favorites.has(p.id);
    const tlClass = p.permit_class === 'success' ? 'green' : (p.permit_class === 'warning' ? 'amber' : 'red');
    let pct = 0;
    if (p.permit_class === 'success') pct = Math.max(20, Math.min(100, (p.permit_days / 365) * 100));
    else if (p.permit_class === 'warning') pct = Math.max(8, (p.permit_days / 30) * 20);
    else if (p.permit_class === 'danger') pct = 100;

    tr.className = isFav ? 'row-pinned' : '';
    tr.innerHTML = `
        <td><input type="checkbox" class="rt-checkbox row-checkbox" data-id="${p.id}"></td>
        <td><button class="fav-btn ${isFav ? 'pinned' : ''}" data-id="${p.id}"><i class="fa-${isFav ? 'solid' : 'regular'} fa-star"></i></button></td>
        <td>
            <strong style="color:var(--text-main);">${escapeHtml(p.name)}</strong>
            ${p.business && p.business !== p.name ? `<br><small style="color:var(--text-muted);">${escapeHtml(p.business)}</small>` : ''}
        </td>
        <td style="color:var(--red-accent); font-weight:700;">${escapeHtml(p.tracking || '—')}</td>
        <td>${escapeHtml(p.barangay || '—')}</td>
        <td>
            <div class="permit-timeline">
                <div style="font-size:12px; font-weight:700;">${formatDate(p.permit_expiry)}</div>
                ${p.permit_class !== 'secondary' ? `
                <div class="permit-timeline-track">
                    <div class="permit-timeline-fill ${tlClass}" style="width:${pct}%;"></div>
                </div>
                <div class="permit-timeline-text">${
                    p.permit_class === 'success' ? p.permit_days + ' days left' :
                    p.permit_class === 'warning' ? p.permit_days + ' days left ⚠️' :
                    Math.abs(p.permit_days) + ' days ago'
                }</div>` : ''}
            </div>
        </td>
        <td><span class="badge-custom badge-${p.permit_class}">${escapeHtml(p.permit_label)}</span></td>
        <td>
            <div class="row-actions">
                <button class="action-btn view-btn" data-id="${p.id}" title="View Details"><i class="fa-solid fa-eye"></i></button>
                <button class="action-btn qr-btn" data-id="${p.id}" title="QR Code"><i class="fa-solid fa-qrcode"></i></button>
                <button class="action-btn chat-btn" data-id="${p.id}" title="Message Owner"><i class="fa-solid fa-comment"></i></button>
            </div>
        </td>
    `;
    return tr;
}

function buildMobileCard(p) {
    const div = document.createElement('div');
    div.className = 'property-mobile-card' + (favorites.has(p.id) ? ' row-pinned' : '');
    div.dataset.id = p.id;
    const isFav = favorites.has(p.id);
    div.innerHTML = `
        <div class="property-card-header">
            <div style="min-width:0; flex:1;">
                <strong style="font-size:13.5px; color:var(--text-main); word-break:break-word;">${escapeHtml(p.name)}</strong>
                ${p.business && p.business !== p.name ? `<br><small style="color:var(--text-muted); font-size:11px;">${escapeHtml(p.business)}</small>` : ''}
            </div>
            <div style="flex-shrink:0; display:flex; align-items:center; gap:6px;">
                <button class="fav-btn ${isFav ? 'pinned' : ''}" data-id="${p.id}"><i class="fa-${isFav ? 'solid' : 'regular'} fa-star"></i></button>
                <span class="badge-custom badge-${p.permit_class}">${escapeHtml(p.permit_label)}</span>
            </div>
        </div>
        <div class="property-card-body">
            <div class="property-card-field">
                <span class="label">Tracking No.</span>
                <span class="value" style="color:var(--red-accent); font-weight:700;">${escapeHtml(p.tracking || '—')}</span>
            </div>
            <div class="property-card-field">
                <span class="label">Barangay</span>
                <span class="value">${escapeHtml(p.barangay || '—')}</span>
            </div>
            <div class="property-card-field" style="grid-column: span 2;">
                <span class="label">Permit Expiry</span>
                <span class="value">${formatDate(p.permit_expiry)}</span>
            </div>
        </div>
        <div class="property-card-actions">
            <button class="pc-btn view-btn" data-id="${p.id}"><i class="fa-solid fa-eye"></i> View</button>
            <button class="pc-btn qr-btn" data-id="${p.id}"><i class="fa-solid fa-qrcode"></i> QR</button>
            <button class="pc-btn chat-btn" data-id="${p.id}"><i class="fa-solid fa-comment"></i> Chat</button>
        </div>
    `;
    return div;
}

function bindRowActions() {
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openDrawer(parseInt(btn.dataset.id, 10));
        });
    });
    document.querySelectorAll('.qr-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openDrawer(parseInt(btn.dataset.id, 10), true);
        });
    });
    document.querySelectorAll('.chat-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toast('💬 Opening chat with owner...');
        });
    });
    document.querySelectorAll('.fav-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.id, 10);
            if (favorites.has(id)) { favorites.delete(id); toast('Removed from pinned'); }
            else { favorites.add(id); toast('⭐ Pinned to top'); }
            localStorage.setItem('rentrack_favs', JSON.stringify([...favorites]));
            renderTable();
        });
    });
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkBar);
    });
    // Row click opens drawer
    document.querySelectorAll('#propertiesTbody tr[data-id]').forEach(tr => {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', (e) => {
            if (e.target.closest('button') || e.target.closest('input')) return;
            openDrawer(parseInt(tr.dataset.id, 10));
        });
    });
    document.querySelectorAll('#mobileCardView .property-mobile-card').forEach(card => {
        card.style.cursor = 'pointer';
        card.addEventListener('click', (e) => {
            if (e.target.closest('button') || e.target.closest('input')) return;
            openDrawer(parseInt(card.dataset.id, 10));
        });
    });
}

/* Filter/Sort listeners */
filterSearch?.addEventListener('input', () => { currentPage = 1; renderTable(); });
filterBarangay?.addEventListener('change', () => { currentPage = 1; renderTable(); });
filterStatus?.addEventListener('change', () => { currentPage = 1; renderTable(); });
sortBy?.addEventListener('change', () => { currentPage = 1; renderTable(); });

groupToggle?.addEventListener('click', () => {
    isGrouped = !isGrouped;
    groupToggle.classList.toggle('active', isGrouped);
    currentPage = 1;
    renderTable();
});

resetFiltersBtn?.addEventListener('click', () => {
    filterSearch.value = '';
    filterBarangay.value = '';
    filterStatus.value = '';
    sortBy.value = 'name-asc';
    isGrouped = false;
    groupToggle.classList.remove('active');
    currentPage = 1;
    renderTable();
    toast('Filters reset');
});

/* Stats card filter shortcuts */
document.querySelectorAll('.stat-card').forEach(card => {
    card.addEventListener('click', () => {
        const f = card.dataset.statFilter;
        document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        if (f === 'all') {
            filterStatus.value = '';
        } else {
            filterStatus.value = f;
        }
        currentPage = 1;
        renderTable();
        // Scroll to list
        document.querySelector('.rt-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

/* Alert banner CTA */
document.getElementById('alertCtaBtn')?.addEventListener('click', () => {
    filterStatus.value = 'warning';
    currentPage = 1;
    renderTable();
    document.querySelector('.rt-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

/* =========================================================
   BULK ACTIONS
========================================================= */
const bulkBar = document.getElementById('bulkBar');
const bulkCountEl = document.getElementById('bulkCount');
const selectAllCheckbox = document.getElementById('selectAllCheckbox');

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const count = checked.length;
    bulkCountEl.textContent = count;
    bulkBar.classList.toggle('show', count > 0);
    if (selectAllCheckbox) {
        const all = document.querySelectorAll('.row-checkbox');
        selectAllCheckbox.checked = all.length > 0 && count === all.length;
        selectAllCheckbox.indeterminate = count > 0 && count < all.length;
    }
}
selectAllCheckbox?.addEventListener('change', () => {
    const on = selectAllCheckbox.checked;
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = on; });
    updateBulkBar();
});
document.getElementById('bulkCloseBtn')?.addEventListener('click', () => {
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = false; });
    updateBulkBar();
});
document.getElementById('bulkClearBtn')?.addEventListener('click', () => {
    document.querySelectorAll('.row-checkbox').forEach(cb => { cb.checked = false; });
    updateBulkBar();
});
document.getElementById('bulkExportBtn')?.addEventListener('click', () => {
    const ids = [...document.querySelectorAll('.row-checkbox:checked')].map(c => parseInt(c.dataset.id, 10));
    const list = PROPERTIES.filter(p => ids.includes(p.id));
    exportToCSV(list);
});
document.getElementById('bulkPinBtn')?.addEventListener('click', () => {
    const ids = [...document.querySelectorAll('.row-checkbox:checked')].map(c => parseInt(c.dataset.id, 10));
    ids.forEach(id => favorites.add(id));
    localStorage.setItem('rentrack_favs', JSON.stringify([...favorites]));
    toast(`⭐ Pinned ${ids.length} propert${ids.length === 1 ? 'y' : 'ies'}`);
    renderTable();
    updateBulkBar();
});

/* =========================================================
   EXPORT CSV + PRINT
========================================================= */
function exportToCSV(list) {
    const rows = [['Property', 'Business', 'Tracking No.', 'Barangay', 'Permit Expiry', 'Compliance']];
    list.forEach(p => rows.push([
        p.name, p.business, p.tracking, p.barangay,
        p.permit_expiry || '', p.permit_label
    ]));
    const csv = rows.map(r => r.map(cell => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const today = new Date().toISOString().slice(0, 10);
    a.href = url;
    a.download = `properties_${today}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast(`📥 Exported ${list.length} propert${list.length === 1 ? 'y' : 'ies'}`);
}
document.getElementById('exportCsvBtn')?.addEventListener('click', () => {
    exportToCSV(getFilteredRows());
});
document.getElementById('printBtn')?.addEventListener('click', () => {
    window.print();
});

/* =========================================================
   DRAWER
========================================================= */
const drawer = document.getElementById('rtDrawer');
const drawerBackdrop = document.getElementById('drawerBackdrop');
const drawerCloseBtn = document.getElementById('drawerCloseBtn');
let qrCodeInstance = null;
let currentDrawerId = null;

function openDrawer(id, focusQr) {
    const p = PROPERTY_MAP[id];
    if (!p) return;
    currentDrawerId = id;

    document.getElementById('drawerTitle').textContent = p.name;
    document.getElementById('drawerSub').textContent = p.barangay ? ('Brgy. ' + p.barangay) : 'No barangay';
    document.getElementById('drawerBusiness').textContent = p.business || '—';
    document.getElementById('drawerTracking').textContent = p.tracking || '—';
    document.getElementById('drawerBarangay').textContent = p.barangay || '—';

    // Permit
    const dt = p.permit_expiry ? new Date(p.permit_expiry) : null;
    document.getElementById('drawerPermitValue').textContent = dt ? dt.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : 'No Record';
    const iconMap = {
        success:   { cls: 'success', icon: 'fa-circle-check' },
        warning:   { cls: 'warning', icon: 'fa-hourglass-half' },
        danger:    { cls: 'danger',  icon: 'fa-circle-exclamation' },
        secondary: { cls: 'secondary', icon: 'fa-circle-question' }
    };
    const im = iconMap[p.permit_class] || iconMap.secondary;
    const iconWrap = document.getElementById('drawerPermitIcon');
    iconWrap.className = 'dp-icon ' + im.cls;
    iconWrap.innerHTML = `<i class="fa-solid ${im.icon}"></i>`;

    const fill = document.getElementById('drawerPermitFill');
    let pct = 0, colorClass = '';
    if (p.permit_class === 'success')   { pct = Math.max(20, Math.min(100, (p.permit_days / 365) * 100)); colorClass = 'green'; }
    else if (p.permit_class === 'warning') { pct = Math.max(8, (p.permit_days / 30) * 20); colorClass = 'amber'; }
    else if (p.permit_class === 'danger')  { pct = 100; colorClass = 'red'; }
    fill.className = 'permit-timeline-fill ' + colorClass;
    fill.style.width = pct + '%';

    let tlText = '';
    if (p.permit_class === 'success') tlText = p.permit_days + ' days remaining';
    else if (p.permit_class === 'warning') tlText = p.permit_days + ' days remaining ⚠️';
    else if (p.permit_class === 'danger') tlText = Math.abs(p.permit_days) + ' days past expiry';
    else tlText = 'No permit record';
    document.getElementById('drawerPermitText').textContent = tlText;

    // Inspections
    document.getElementById('drawerInspTotal').textContent = p.inspection_count;
    document.getElementById('drawerInspCompleted').textContent = p.completed_count;
    document.getElementById('drawerInspFailed').textContent = p.failed_count;
    document.getElementById('drawerLastInsp').textContent = formatDate(p.last_insp_date);
    document.getElementById('drawerLastStatus').textContent = p.last_insp_status || '—';

    // QR
    const qrWrap = document.getElementById('drawerQrWrap');
    qrWrap.innerHTML = '';
    if (typeof QRCode !== 'undefined') {
        try {
            new QRCode(qrWrap, {
                text: p.tracking || ('PROP-' + p.id),
                width: 140, height: 140,
                colorDark: '#0f172a', colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } catch (err) { qrWrap.innerHTML = '<div style="color:var(--text-muted); font-size:11px;">QR unavailable</div>'; }
    } else {
        qrWrap.innerHTML = '<div style="color:var(--text-muted); font-size:11px;">QR library not loaded</div>';
    }

    drawer.classList.add('active');
    drawer.setAttribute('aria-hidden', 'false');
    drawerBackdrop.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Scroll to QR if focusQr
    if (focusQr) {
        setTimeout(() => {
            document.querySelector('.drawer-qr')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 350);
    }
}
function closeDrawer() {
    drawer.classList.remove('active');
    drawer.setAttribute('aria-hidden', 'true');
    drawerBackdrop.classList.remove('active');
    document.body.style.overflow = '';
    currentDrawerId = null;
}
drawerCloseBtn?.addEventListener('click', closeDrawer);
drawerBackdrop?.addEventListener('click', closeDrawer);
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer.classList.contains('active')) closeDrawer();
});
document.getElementById('drawerChatBtn')?.addEventListener('click', () => {
    toast('💬 Opening chat with owner...');
});
document.getElementById('drawerCopyTracking')?.addEventListener('click', () => {
    const p = PROPERTY_MAP[currentDrawerId];
    if (!p) return;
    navigator.clipboard.writeText(p.tracking || '').then(() => toast('📋 Tracking copied')).catch(() => toast('Copy failed', true));
});

/* =========================================================
   CHART
========================================================= */
(function initChart() {
    const canvas = document.getElementById('complianceChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const data = {
        labels: ['Valid', 'Expiring', 'Expired', 'No Record'],
        values: [
            <?php echo $statValid; ?>,
            <?php echo $statExpiring; ?>,
            <?php echo $statExpired; ?>,
            <?php echo $statNoRecord; ?>
        ]
    };
    if (data.values.reduce((a, b) => a + b, 0) === 0) return;

    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';

    window.__complianceChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: ['#059669', '#d97706', '#dc2626', '#94a3b8'],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
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
                    callbacks: {
                        label: (ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                            return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
})();

/* =========================================================
   INIT
========================================================= */
renderTable();

/* Resize */
window.addEventListener('resize', () => {
    if (window.innerWidth > 992) {
        document.body.classList.remove('mobile-nav-open');
        document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>