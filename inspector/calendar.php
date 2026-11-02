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

// Which month are we viewing? ?month=2026-08
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$viewYear  = (int) substr($monthParam, 0, 4);
$viewMonth = (int) substr($monthParam, 5, 2);
if ($viewMonth < 1 || $viewMonth > 12) {
    $viewYear = (int) date('Y');
    $viewMonth = (int) date('n');
}

$firstOfMonth = mktime(0, 0, 0, $viewMonth, 1, $viewYear);
$daysInMonth  = (int) date('t', $firstOfMonth);
$startWeekday = (int) date('w', $firstOfMonth); // 0=Sun

$prevMonthStamp = mktime(0, 0, 0, $viewMonth - 1, 1, $viewYear);
$nextMonthStamp = mktime(0, 0, 0, $viewMonth + 1, 1, $viewYear);
$prevMonthStr = date('Y-m', $prevMonthStamp);
$nextMonthStr = date('Y-m', $nextMonthStamp);
$monthLabel = date('F Y', $firstOfMonth);

// Fetch this inspector's inspections that fall within the visible month
$rangeStart = date('Y-m-d', $firstOfMonth);
$rangeEnd = date('Y-m-d', mktime(0, 0, 0, $viewMonth, $daysInMonth, $viewYear));

$byDay = []; // 'YYYY-MM-DD' => [rows]

if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $stmt = $conn->prepare("
        SELECT i.id, i.inspection_date, i.inspection_time, i.inspection_type, i.inspection_status,
               r.rental_name, r.business_name, r.barangay
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid
          AND i.inspection_date BETWEEN :rstart AND :rend
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        ORDER BY i.inspection_date ASC, i.inspection_time ASC
    ");
    $stmt->execute([
        ':iid' => $inspectorId,
        ':rstart' => $rangeStart,
        ':rend' => $rangeEnd
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = date('Y-m-d', strtotime($row['inspection_date']));
        $byDay[$d][] = $row;
    }
}

$statusDot = [
    'scheduled' => '#f59e0b',
    'ongoing'   => '#3b82f6',
    'completed' => '#059669',
    'passed'    => '#059669',
    'failed'    => '#dc2626',
    'cancelled' => '#64748b',
    'pending'   => '#94a3b8',
];
$todayStr = date('Y-m-d');

$totalAssigned = 0;
$unreadInspectionsCount = 0;
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $totalStmt = $conn->prepare("
        SELECT COUNT(*) FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid 
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
    ");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#ffffff">
<title>Calendar | RENTRACK LGU</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

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
    .rt-panel { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

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
    .rt-search-trigger:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .rt-search-trigger:hover i.fa-magnifying-glass { color: var(--red-accent); }
    .rt-search-trigger .search-placeholder { flex: 1; text-overflow: ellipsis; overflow: hidden; }
    .rt-search-trigger .kbd-hint {
        background: #fff; border: 1px solid var(--border-color); color: var(--text-muted);
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
    .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .mobile-search-btn { display: none; }
    .mobile-menu-btn { display: none; }

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

    /* ============ SEARCH MODAL ============ */
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
    .search-modal-overlay.active { opacity: 1; visibility: visible; }

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
    .search-modal-overlay.active .search-modal-card { transform: translateY(0) scale(1); }

    .search-modal-header {
        display: flex; align-items: center; gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
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
    .search-modal-header .sm-esc:hover {
        background: var(--red-soft); border-color: var(--red-accent); color: var(--red-accent);
    }

    .search-modal-body { max-height: 420px; overflow-y: auto; padding: 8px; }
    .search-modal-body::-webkit-scrollbar { width: 6px; }
    .search-modal-body::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }

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
        background: #fff; border: 1px solid var(--border-color);
        color: var(--text-sub); font-size: 10px; font-weight: 700;
        padding: 2px 6px; border-radius: 4px;
        font-family: 'Space Grotesk', sans-serif; letter-spacing: 0.5px;
    }

    /* ============ WRAPPER / CONTENT ============ */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

    .rt-panel {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; padding: 20px;
        box-shadow: var(--shadow-soft);
    }

    .rt-title {
        font-family: 'Space Grotesk', sans-serif;
        color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
        letter-spacing: -0.2px;
    }

    .rt-page-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }

    /* ============ CALENDAR ============ */
    .cal-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .cal-toolbar h4 { margin: 0; font-weight: 800; color: var(--text-main); font-size: 18px; font-family: 'Space Grotesk', sans-serif; }
    @media (min-width: 768px) { .cal-toolbar h4 { font-size: 20px; } }

    .cal-nav-group { display: flex; align-items: center; gap: 8px; }
    .cal-nav-btn {
        background: var(--red-soft); border: 1px solid #fecdd3; color: var(--red-accent);
        width: 36px; height: 36px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        text-decoration: none; font-size: 13px; transition: 0.2s;
        -webkit-tap-highlight-color: transparent;
    }
    .cal-nav-btn:active { transform: scale(0.9); }
    .cal-nav-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    .cal-today-btn {
        background: var(--red-accent); color: #fff; border: 1px solid var(--red-accent);
        padding: 7px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700;
        text-decoration: none; transition: 0.2s;
    }
    .cal-today-btn:hover { background: #991b1b; color: #fff; border-color: #991b1b; }

    .cal-legend {
        display: flex; gap: 12px; flex-wrap: wrap;
        font-size: 11.5px; color: var(--text-muted);
    }
    .cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .cal-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

    .cal-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 6px;
        width: 100%;
    }
    @media (min-width: 768px) { .cal-grid { gap: 8px; } }

    .cal-head {
        text-align: center; font-size: 10.5px; font-weight: 800; text-transform: uppercase;
        color: var(--red-accent); padding: 6px 0; letter-spacing: 0.04em;
    }
    @media (min-width: 768px) { .cal-head { font-size: 11.5px; padding-bottom: 8px; } }

    .cal-cell {
        background: #f8fafc; border: 1px solid var(--border-color);
        border-radius: 8px; min-height: 68px; padding: 5px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        display: flex; flex-direction: column; gap: 3px;
        overflow: hidden; position: relative;
    }
    @media (min-width: 576px) { .cal-cell { min-height: 82px; padding: 6px; border-radius: 10px; gap: 4px; } }
    @media (min-width: 768px) { .cal-cell { border-radius: 12px; min-height: 110px; padding: 8px; gap: 4px; } }

    .cal-cell.empty { background: transparent; border: none; box-shadow: none; }
    .cal-cell.today {
        border: 2px solid var(--red-accent);
        background: var(--red-soft);
        box-shadow: 0 0 12px rgba(220,38,38,0.25);
    }
    .cal-daynum { font-size: 10.5px; font-weight: 700; color: var(--text-main); line-height: 1; }
    .cal-cell.today .cal-daynum { color: var(--red-accent); font-weight: 800; }
    @media (min-width: 576px) { .cal-daynum { font-size: 11.5px; } }
    @media (min-width: 768px) { .cal-daynum { font-size: 12.5px; } }

    .cal-event {
        font-size: 9px; padding: 2px 4px; border-radius: 4px;
        background: var(--red-soft); color: var(--red-accent);
        text-decoration: none; display: flex; align-items: center; gap: 4px;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        border: 1px solid #fecdd3; line-height: 1.2;
        -webkit-tap-highlight-color: transparent;
        transition: 0.2s;
    }
    @media (min-width: 576px) { .cal-event { font-size: 10px; padding: 2px 5px; gap: 5px; } }
    @media (min-width: 768px) { .cal-event { font-size: 11px; padding: 3px 6px; border-radius: 6px; gap: 5px; } }
    .cal-event:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }
    .cal-event .cal-dot { width: 6px; height: 6px; }
    @media (min-width: 768px) { .cal-event .cal-dot { width: 7px; height: 7px; } }

    .cal-more { font-size: 8.5px; color: var(--text-muted); font-weight: 600; padding-left: 2px; }
    @media (min-width: 576px) { .cal-more { font-size: 9.5px; } }
    @media (min-width: 768px) { .cal-more { font-size: 10.5px; } }

    @media (max-width: 359px) {
        .cal-event span.event-text { display: none; }
        .cal-event { justify-content: center; padding: 3px; }
    }

    /* Footer */
    .footer {
        margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
        border-top: 1px solid var(--border-color); display: flex;
        justify-content: space-between; align-items: center; font-size: 11.5px;
        color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
    }
    .footer strong { color: var(--red-accent); }

    /* Modal */
    .modal-content {
        background: #ffffff !important; border: 1px solid var(--border-color) !important;
        color: var(--text-main) !important; border-radius: 16px !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    }
    .modal-header { border-bottom: 1px solid var(--border-color) !important; padding: 14px 18px; }
    .modal-footer { border-top: 1px solid var(--border-color) !important; padding: 12px 18px; }
    .modal-body { padding: 16px; }
    .modal-title { color: var(--red-accent) !important; }

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
    @media (max-width: 768px) {
        .rt-topbar-brand-text strong { font-size: 12px; }
        .rt-topbar-right { gap: 8px; }
        .dashboard-shell { padding: 14px 14px 24px; }
        .rt-panel { padding: 16px; }
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
        .rt-title { font-size: 16px; }
        .footer { font-size: 10px; flex-direction: column; text-align: center; }
        .search-modal-overlay { padding: 60px 10px 10px; }
        .search-modal-card { border-radius: 14px; }
        .search-modal-header { padding: 12px 14px; gap: 10px; }
        .search-modal-header .sm-icon { width: 34px; height: 34px; font-size: 13px; }
        .search-modal-header input { font-size: 15px; }
    }
    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .search-modal-header input { font-size: 16px !important; }
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

            <!-- FIELD OPS -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn has-active">
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
                    <a href="calendar.php" class="active"><i class="fa-regular fa-calendar"></i> Calendar</a>
                    <a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> Findings</a>
                </div>
            </div>

            <!-- REPORTS -->
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

            <!-- ACCOUNT -->
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
            </button>
            <div id="notifDropdown" style="display:none; position:absolute; top:48px; right:0; width:300px; max-width:calc(100vw - 20px); background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
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
                <input type="text" id="searchModalInput" placeholder="Search calendar events, properties..." autocomplete="off" spellcheck="false">
            </div>
            <button type="button" class="sm-esc" id="searchModalClose">ESC</button>
        </div>

        <div class="search-modal-body" id="searchModalBody">
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Calendar</div>
                <div class="sm-state-sub">Filter scheduled inspections by property name, barangay, or status.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Property</span>
                    <span class="sm-tip"><i class="fa-solid fa-location-dot"></i> Barangay</span>
                    <span class="sm-tip"><i class="fa-solid fa-circle-check"></i> Status</span>
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
            <div class="rt-panel">

                <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <div>
                        <h2 class="rt-title">Calendar View</h2>
                        <p class="rt-page-sub">Monthly timeline of your assigned inspections.</p>
                    </div>
                    <span style="display:inline-flex; align-items:center; gap:7px; background:rgba(5,150,105,0.1); border:1px solid rgba(5,150,105,0.2); color:var(--emerald-accent); font-size:11px; font-weight:700; padding:6px 12px; border-radius:999px;">
                        <span style="width:7px; height:7px; border-radius:50%; background:var(--emerald-accent); box-shadow:0 0 8px var(--emerald-accent);"></span>
                        <?php echo $totalAssigned; ?> total assigned
                    </span>
                </div>

                <!-- CALENDAR CARD -->
                <div style="background:#fff; border:1px solid var(--border-color); border-radius:16px; padding:20px; box-shadow:var(--shadow-soft);">

                    <div class="cal-toolbar">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <div class="cal-nav-group">
                                <a href="?month=<?php echo $prevMonthStr; ?>" class="cal-nav-btn" title="Previous month">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>
                                <h4 style="margin:0 6px;"><?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?></h4>
                                <a href="?month=<?php echo $nextMonthStr; ?>" class="cal-nav-btn" title="Next month">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            </div>
                            <a href="?month=<?php echo date('Y-m'); ?>" class="cal-today-btn">Today</a>
                        </div>
                        <div class="cal-legend">
                            <span><i class="cal-dot" style="background:#f59e0b;"></i> Scheduled</span>
                            <span><i class="cal-dot" style="background:#3b82f6;"></i> Ongoing</span>
                            <span><i class="cal-dot" style="background:#059669;"></i> Passed</span>
                            <span><i class="cal-dot" style="background:#dc2626;"></i> Failed</span>
                        </div>
                    </div>

                    <div class="cal-grid">
                        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd) { ?>
                            <div class="cal-head"><?php echo $wd; ?></div>
                        <?php } ?>

                        <?php for ($i = 0; $i < $startWeekday; $i++) { ?>
                            <div class="cal-cell empty"></div>
                        <?php } ?>

                        <?php for ($d = 1; $d <= $daysInMonth; $d++) {
                            $dateStr = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $d);
                            $dayEvents = $byDay[$dateStr] ?? [];
                            $isToday = $dateStr === $todayStr;
                        ?>
                            <div class="cal-cell <?php echo $isToday ? 'today' : ''; ?>"
                                 data-day="<?php echo $dateStr; ?>"
                                 data-search="<?php
                                    $blob = '';
                                    foreach ($dayEvents as $ev) {
                                        $blob .= strtolower(($ev['rental_name'] ?? '') . ' ' . ($ev['business_name'] ?? '') . ' ' . ($ev['barangay'] ?? '') . ' ' . ($ev['inspection_status'] ?? '') . ' ');
                                    }
                                    echo htmlspecialchars(trim($blob), ENT_QUOTES, 'UTF-8');
                                 ?>">
                                <div class="cal-daynum"><?php echo $d; ?></div>
                                <?php
                                $shown = 0;
                                foreach ($dayEvents as $ev) {
                                    if ($shown >= 2) break;
                                    $key = strtolower(trim($ev['inspection_status'] ?: 'pending'));
                                    $dot = $statusDot[$key] ?? '#94a3b8';
                                    $label = $ev['rental_name'] ?? $ev['business_name'] ?? 'Inspection';
                                ?>
                                    <a href="inspections.php" class="cal-event" title="<?php echo htmlspecialchars($label . ' - ' . ($ev['inspection_status'] ?: 'Pending'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="cal-dot" style="background:<?php echo $dot; ?>;"></span>
                                        <span class="event-text"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php
                                    $shown++;
                                }
                                if (count($dayEvents) > 2) {
                                ?>
                                    <span class="cal-more">+<?php echo count($dayEvents) - 2; ?> more</span>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>

                </div>

            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong>RENTRACK LGU</strong> &middot; Inspector Portal</span>
                <span>Unified Rental Registration &amp; Compliance System</span>
            </footer>
        </div>
    </div>
</div>

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
                <button type="button" class="btn px-4 py-2" data-bs-dismiss="modal" style="font-size:12px; background:#f1f5f9; border:1px solid #e2e8f0; color:#0f172a;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn px-4 py-2" style="font-size:12px; background:#dc2626; border:none; color:#fff;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
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

    /* ============ SEARCH MODAL ============ */
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
                <div class="sm-state-title">Search Calendar</div>
                <div class="sm-state-sub">Filter scheduled inspections by property name, barangay, or status.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Property</span>
                    <span class="sm-tip"><i class="fa-solid fa-location-dot"></i> Barangay</span>
                    <span class="sm-tip"><i class="fa-solid fa-circle-check"></i> Status</span>
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

    /* Live filter day cells */
    searchInput?.addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();

        // Reset highlight
        document.querySelectorAll('.cal-cell').forEach(c => {
            c.style.boxShadow = '';
            c.style.border = '';
        });

        if (q.length < 2) {
            resetSearchBody();
            return;
        }

        const matches = [];
        document.querySelectorAll('.cal-cell[data-day]').forEach(cell => {
            const blob = cell.dataset.search || '';
            if (blob.includes(q)) {
                matches.push(cell);
            }
        });

        searchBody.innerHTML = '';
        if (matches.length === 0) {
            searchBody.innerHTML = `
                <div class="sm-state">
                    <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div class="sm-state-title">No results found</div>
                    <div class="sm-state-sub">No scheduled events matched "<strong>${escapeHtml(q)}</strong>".</div>
                </div>
            `;
            return;
        }

        const label = document.createElement('div');
        label.className = 'sm-result-group-label';
        label.textContent = 'Matching Days · ' + matches.length;
        searchBody.appendChild(label);

        matches.slice(0, 8).forEach(cell => {
            const dayNum = cell.querySelector('.cal-daynum')?.textContent?.trim() || '';
            const dayDate = cell.dataset.day || '';
            const eventCount = cell.querySelectorAll('.cal-event').length;
            const dateObj = new Date(dayDate + 'T00:00:00');
            const dateDisplay = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

            const a = document.createElement('a');
            a.href = 'javascript:void(0)';
            a.className = 'sm-result-item';
            a.innerHTML = `
                <div class="sm-result-icon"><i class="fa-regular fa-calendar-check"></i></div>
                <div class="sm-result-body">
                    <div class="sm-result-type">Day ${dayNum} · ${eventCount} event${eventCount !== 1 ? 's' : ''}</div>
                    <div class="sm-result-title">${escapeHtml(dateDisplay)}</div>
                    <div class="sm-result-sub">Click to scroll & highlight</div>
                </div>
                <i class="fa-solid fa-arrow-right sm-result-arrow"></i>
            `;
            a.addEventListener('click', () => {
                closeSearchModal();
                cell.scrollIntoView({ behavior: 'smooth', block: 'center' });
                cell.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.4)';
                cell.style.border = '2px solid var(--red-accent)';
                setTimeout(() => {
                    cell.style.boxShadow = '';
                    cell.style.border = '';
                }, 2500);
            });
            searchBody.appendChild(a);
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* ============ RESIZE HANDLER ============ */
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            document.body.classList.remove('mobile-nav-open');
            document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
        }
    });
</script>
</body>
</html>