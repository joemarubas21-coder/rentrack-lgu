<?php
include "includes/auth.php";
include "../config/db.php";
include "includes/inspector_features.php";
date_default_timezone_set('Asia/Manila');

$inspectorId = (int)($_SESSION['user_id'] ?? 0);
$id          = (int)($_GET['id'] ?? $_POST['inspection_id'] ?? 0);
$inspection  = inspector_get_owned_inspection($conn, $id, $inspectorId);

if (!$inspection) {
    http_response_code(404);
    exit('Inspection not found or not assigned to you.');
}

$fullname  = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = htmlspecialchars($_SESSION['role'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');

$msg = '';
$err = '';

/* =====================================================
   HANDLE POST (PDO)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inspector_check_csrf($_POST['csrf'] ?? '')) {
    $rec      = trim($_POST['recommendation'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['Low','Normal','High','Urgent'], true) ? $_POST['priority'] : 'Normal';
    $date     = $_POST['target_date'] ?? '';
    $status   = in_array($_POST['status'] ?? '', ['Recommended','In Progress','Completed','Deferred'], true) ? $_POST['status'] : 'Recommended';
    $notes    = trim($_POST['notes'] ?? '');

    if (!$rec) {
        $err = 'Maintenance recommendation is required.';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO inspector_maintenance 
                    (inspection_id, recommendation, priority, target_date, status, notes, created_by)
                VALUES 
                    (:iid, :rec, :priority, :target_date, :status, :notes, :created_by)
            ");
            $stmt->execute([
                ':iid'         => $id,
                ':rec'         => $rec,
                ':priority'    => $priority,
                ':target_date' => $date !== '' ? $date : null,
                ':status'      => $status,
                ':notes'       => $notes,
                ':created_by'  => $inspectorId,
            ]);
            $msg = 'Maintenance recommendation saved.';
        } catch (PDOException $e) {
            $err = 'Database error: ' . safe_error($e);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = 'Security token expired. Please refresh and try again.';
}

/* =====================================================
   FETCH ROWS (PDO)
===================================================== */
$rows = [];
try {
    $stmt = $conn->prepare("SELECT * FROM inspector_maintenance WHERE inspection_id = :iid ORDER BY created_at DESC");
    $stmt->execute([':iid' => $id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

/* Priority badge helper */
function priorityBadge($p) {
    $p = strtolower(trim((string)$p));
    $map = [
        'urgent' => 'danger',
        'high'   => 'warning text-dark',
        'normal' => 'info',
        'low'    => 'secondary',
    ];
    return $map[$p] ?? 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Maintenance Recommendations | RENTRACK LGU</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-orange: #ff7a00;
        --primary-orange-dark: #d95300;
        --primary-orange-light: #ffb067;
        --sidebar-bg: rgba(20, 11, 7, 0.95);
        --topbar-gov-bg: #140b07;
        --sidebar-hover: rgba(255, 122, 0, 0.15);
        --card-bg: rgba(31, 20, 15, 0.85);
        --card-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        --border-light: rgba(255, 122, 0, 0.25);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background-color: #0e0704;
        background-image: 
            radial-gradient(circle at 15% 15%, rgba(255, 122, 0, 0.15) 0%, transparent 45%),
            radial-gradient(circle at 85% 85%, rgba(224, 109, 0, 0.1) 0%, transparent 45%),
            radial-gradient(circle at 50% 50%, rgba(255, 159, 67, 0.06) 0%, transparent 60%);
        background-attachment: fixed;
        color: #ffffff;
        overflow-x: hidden;
    }

    /* Gov top bar */
    .gov-top-bar {
        background: var(--topbar-gov-bg); color: #ffb067; padding: 5px 10px;
        font-size: 9px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid var(--border-light); z-index: 100; flex-wrap: nowrap; overflow: hidden;
        white-space: nowrap; gap: 6px;
    }
    @media (min-width: 768px) {
        .gov-top-bar { padding: 8px 24px; font-size: 11.5px; white-space: normal; flex-wrap: wrap; }
    }
    .gov-badge { background: rgba(255, 122, 0, 0.25); color: #ffb067; padding: 2px 5px; border-radius: 4px; font-size: 8.5px; font-weight: 700; border: 1px solid rgba(255, 122, 0, 0.5); }

    @keyframes spinSlow { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
    .wrapper { display: flex; width: 100%; min-height: calc(100vh - 37px); align-items: stretch; position: relative; }
    .main { flex: 1; min-width: 0; background: transparent; display: flex; flex-direction: column; transition: all 0.3s ease; }

    /* Sidebar */
    .sidebar {
        width: 280px; background: var(--sidebar-bg); backdrop-filter: blur(16px);
        color: #fff; min-height: 100vh; flex-shrink: 0; border-right: 1px solid var(--border-light);
        display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh;
        overflow-y: auto; transition: transform 0.3s ease, width 0.3s ease; z-index: 1050;
        transform: translateX(-100%);
    }
    .sidebar.mobile-show { transform: translateX(0); }
    @media (min-width: 992px) { .sidebar { position: sticky; transform: translateX(0); } }
    .sidebar-brand { padding: 18px 16px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-light); }
    @media (min-width: 768px) { .sidebar-brand { padding: 22px 20px; gap: 14px; } }
    .brand-icon-wrapper { position: relative; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; }
    @media (min-width: 768px) { .brand-icon-wrapper { width: 44px; height: 44px; } }
    .brand-spinner { position: absolute; width: 100%; height: 100%; border: 2px dashed var(--primary-orange); border-radius: 50%; animation: spinSlow 8s linear infinite; }
    .brand-icon { width: 28px; height: 28px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #fff; box-shadow: 0 0 15px rgba(255, 122, 0, 0.6); z-index: 2; }
    @media (min-width: 768px) { .brand-icon { width: 34px; height: 34px; font-size: 15px; } }
    .brand-text .main-title { font-size: 14px; font-weight: 800; color: #fff; line-height: 1.1; letter-spacing: 0.5px; }
    @media (min-width: 768px) { .brand-text .main-title { font-size: 16px; } }
    .brand-text .sub-title { font-size: 8.5px; font-weight: 600; color: var(--primary-orange-light); text-transform: uppercase; letter-spacing: 1px; }
    @media (min-width: 768px) { .brand-text .sub-title { font-size: 9.5px; } }
    .sidebar-category { font-size: 9.5px; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255,255,255,0.6); padding: 14px 16px 4px 16px; font-weight: 700; }
    @media (min-width: 768px) { .sidebar-category { font-size: 10px; padding: 18px 20px 6px 20px; } }
    .sidebar-nav { list-style: none; padding: 0 10px; margin: 0; }
    .sidebar-nav li { margin-bottom: 2px; }
    .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; color: #f3f4f6; text-decoration: none; border-radius: 10px; font-size: 13px; font-weight: 500; transition: all 0.2s ease; }
    @media (min-width: 768px) { .sidebar-nav a { padding: 10px 14px; font-size: 13.5px; gap: 14px; } }
    .sidebar-nav a i { font-size: 14px; width: 20px; text-align: center; color: var(--primary-orange-light); }
    .sidebar-nav a:hover { background: var(--sidebar-hover); color: #fff; }
    .sidebar-nav a.active { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark)); color: #fff; box-shadow: 0 4px 15px rgba(255, 122, 0, 0.4); }
    .sidebar-nav a.active i { color: #fff; }
    .sidebar-footer { padding: 14px; border-top: 1px solid var(--border-light); margin-top: auto; display: flex; justify-content: center; }
    .sidebar-logout-btn { width: 44px; height: 44px; background: rgba(255,122,0,0.08); border: 1.5px solid var(--primary-orange); color: var(--primary-orange-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: all 0.3s ease; }
    .sidebar-logout-btn:hover { background: var(--primary-orange); color: #fff; }

    /* Top navbar */
    .top-navbar { background: rgba(20, 11, 7, 0.95); backdrop-filter: blur(12px); padding: 8px 12px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; position: sticky; top: 0; z-index: 30; color: #fff; }
    @media (min-width: 768px) { .top-navbar { padding: 12px 28px; gap: 12px; } }
    .hamburger-btn { background: rgba(255,122,0,0.15); border: 1px solid var(--border-light); color: var(--primary-orange-light); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; flex-shrink: 0; }
    .navbar-title-wrap { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .navbar-title-wrap h4 { font-size: 13.5px; font-weight: 700; margin: 0; color: #fff; display: flex; align-items: center; gap: 6px; }
    @media (min-width: 768px) { .navbar-title-wrap h4 { font-size: 17px; } }

    /* Content */
    .content { flex: 1; padding: 12px; }
    @media (min-width: 768px) { .content { padding: 28px 32px 20px; } }

    /* Cards */
    .feature-card { background: var(--card-bg); backdrop-filter: blur(12px); border: 1px solid var(--border-light); border-radius: 16px; box-shadow: var(--card-shadow); margin-bottom: 20px; color: #fff; }
    .feature-card .card-header { background: rgba(255, 122, 0, 0.06); border-bottom: 1px solid var(--border-light); font-weight: 700; padding: 16px 20px; color: #fff; border-radius: 16px 16px 0 0 !important; }
    .feature-card .card-body { color: #e5e7eb; }

    /* Form controls — dark */
    .form-control, .form-select {
        background: #140b07 !important; border: 1px solid var(--border-light) !important;
        color: #fff !important; border-radius: 10px;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--primary-orange) !important;
        box-shadow: 0 0 0 0.2rem rgba(255,122,0,0.25) !important;
        background: #140b07 !important; color: #fff !important;
    }
    .form-control::placeholder { color: #9ca3af !important; }

    /* Page title */
    .page-title h2 { font-size: 18px; font-weight: 800; color: #fff; margin-bottom: 4px; }
    @media (min-width: 768px) { .page-title h2 { font-size: 24px; } }
    .page-title p { font-size: 12.5px; color: #d1d5db; margin: 0; }

    /* Buttons */
    .btn-warning { background: var(--primary-orange) !important; border: none !important; color: #fff !important; font-weight: 700; }
    .btn-warning:hover { background: var(--primary-orange-dark) !important; }
    .btn-outline-secondary { color: #d1d5db !important; border-color: var(--border-light) !important; }
    .btn-outline-secondary:hover { background: rgba(255,122,0,0.15) !important; color: #fff !important; border-color: var(--primary-orange) !important; }
    .btn-outline-warning { color: #ffb067 !important; border-color: rgba(255,122,0,0.5) !important; }
    .btn-outline-warning:hover { background: var(--primary-orange) !important; color: #fff !important; }

    /* Table */
    .table-dark-custom { color: #e5e7eb; width: 100%; border-collapse: collapse; }
    .table-dark-custom thead th {
        background: rgba(255,122,0,0.08); color: #ffb067 !important;
        font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
        font-weight: 700; padding: 12px 14px; border-bottom: 1px solid var(--border-light);
        white-space: nowrap;
    }
    .table-dark-custom tbody td {
        padding: 12px 14px; border-bottom: 1px solid rgba(255,122,0,0.15);
        font-size: 13px; color: #e5e7eb; vertical-align: middle;
    }
    .table-dark-custom tbody tr:hover { background: rgba(255,122,0,0.06); }

    /* Footer */
    .footer-custom { background: rgba(20,11,7,0.7); backdrop-filter: blur(10px); border-top: 1px solid var(--border-light); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 11px; color: #d1d5db; margin-top: auto; font-weight: 500; }
    @media (min-width: 768px) { .footer-custom { padding: 14px 32px; } }
    .footer-custom .brand { color: var(--primary-orange); font-weight: 800; }

    /* Dropdown dark */
    .dropdown-menu { background: #251610 !important; border: 1px solid var(--border-light) !important; }
    .dropdown-item { color: #e5e7eb !important; }
    .dropdown-item:hover { background: var(--primary-orange) !important; color: #fff !important; }
    .dropdown-divider { border-color: rgba(255,122,0,0.25) !important; }
</style>
</head>
<body>

<!-- Gov Top Bar -->
<div class="gov-top-bar">
    <div class="gov-left text-truncate">
        <i class="fa-solid fa-landmark text-warning"></i>
        <span class="text-truncate">REP. OF THE PHILIPPINES <span class="d-none d-sm-inline">| LGU Compliance Portal</span></span>
    </div>
    <div class="gov-right">
        <span class="gov-badge"><i class="fa-solid fa-shield-halved me-1"></i> LGU V2.1</span>
    </div>
</div>

<div class="wrapper">
    <!-- HARDCODED SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon-wrapper">
                <div class="brand-spinner"></div>
                <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
            </div>
            <div class="brand-text">
                <div class="main-title">RENTRACK</div>
                <div class="sub-title">INSPECTOR</div>
            </div>
        </div>

        <div class="sidebar-category">Main Navigation</div>
        <ul class="sidebar-nav">
            <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span></a></li>
            <li><a href="inspections.php" class="active"><i class="fa-solid fa-clipboard-check"></i> <span>Inspections</span></a></li>
            <li><a href="calendar.php"><i class="fa-regular fa-calendar"></i> <span>Calendar</span></a></li>
            <li><a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> <span>Findings</span></a></li>
            <li><a href="reports.php"><i class="fa-solid fa-file-lines"></i> <span>Reports</span></a></li>
        </ul>

        <div class="sidebar-category">Field Ops &amp; Records</div>
        <ul class="sidebar-nav">
            <li><a href="properties.php"><i class="fa-solid fa-building"></i> <span>Properties</span></a></li>
        </ul>

        <div class="sidebar-category">Account</div>
        <ul class="sidebar-nav">
            <li><a href="profile.php"><i class="fa-regular fa-user"></i> <span>Profile</span></a></li>
            <li><a href="settings.php"><i class="fa-solid fa-gear"></i> <span>Settings</span></a></li>
        </ul>

        <div class="sidebar-footer">
            <a href="#" class="sidebar-logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal" title="Logout System">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <div class="main">
        <!-- HARDCODED TOP NAVBAR -->
        <header class="top-navbar">
            <div class="navbar-title-wrap">
                <button class="hamburger-btn" id="sidebarToggle" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4><i class="fa-solid fa-screwdriver-wrench" style="color:#ff7a00"></i> Maintenance</h4>
            </div>

            <div class="navbar-right d-flex align-items-center gap-2">
                <div class="dropdown">
                    <button class="btn btn-dark d-flex align-items-center gap-2 dropdown-toggle text-white rounded-pill px-2 py-1" type="button" data-bs-toggle="dropdown" style="background:rgba(255,122,0,0.1) !important;border-color:var(--border-light) !important;">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($fullname); ?>&background=ff7a00&color=fff" class="rounded-circle" width="26" height="26">
                        <div class="text-start lh-1 d-none d-sm-block">
                            <span class="fw-semibold small d-block"><?php echo $fullname; ?></span>
                            <span class="text-uppercase" style="font-size: 8.5px; font-weight: 700; color: #ffb067;"><?php echo $staffRole; ?></span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="min-width:180px;">
                        <li><a class="dropdown-item py-2 small" href="profile.php"><i class="fa-solid fa-user me-2"></i> Profile</a></li>
                        <li><a class="dropdown-item py-2 small" href="settings.php"><i class="fa-solid fa-gear me-2"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger small" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="page-title mb-4">
                <h2><i class="fa-solid fa-screwdriver-wrench" style="color:#ff7a00"></i> Maintenance Recommendations</h2>
                <p>Recommend repair, maintenance, or corrective action for inspected properties.</p>
            </div>

            <?php if($msg) echo inspector_flash('success',$msg); if($err) echo inspector_flash('danger',$err); ?>

            <!-- NEW RECOMMENDATION FORM -->
            <div class="card feature-card">
                <div class="card-header">
                    <i class="fa-solid fa-plus-circle me-2" style="color:#ff7a00"></i> New Recommendation
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf" value="<?php echo inspector_csrf(); ?>">
                        <input type="hidden" name="inspection_id" value="<?php echo $id; ?>">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Recommendation</label>
                            <textarea name="recommendation" class="form-control" rows="3" placeholder="Describe the repair or corrective action needed..." required></textarea>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Priority</label>
                            <select name="priority" class="form-select">
                                <option>Low</option>
                                <option selected>Normal</option>
                                <option>High</option>
                                <option>Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Target Date</label>
                            <input type="date" name="target_date" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option>Recommended</option>
                                <option>In Progress</option>
                                <option>Completed</option>
                                <option>Deferred</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes or additional details..."></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button class="btn btn-warning px-4" type="submit">
                                <i class="fa-solid fa-plus me-1"></i> Save Recommendation
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- RECOMMENDATIONS TABLE -->
            <div class="card feature-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fa-solid fa-list me-2" style="color:#ff7a00"></i> Recommendations (<?php echo count($rows); ?>)</span>
                    <a href="inspection_form.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Inspection Form
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table-dark-custom">
                            <thead>
                                <tr>
                                    <th>Recommendation</th>
                                    <th>Priority</th>
                                    <th>Target Date</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="fa-regular fa-clipboard fa-2x d-block mb-2" style="color:#ffb067; opacity:0.5;"></i>
                                            No recommendations recorded yet.
                                        </td>
                                    </tr>
                                <?php else: foreach($rows as $r): ?>
                                    <tr>
                                        <td><?php echo nl2br(inspector_e($r['recommendation'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo priorityBadge($r['priority']); ?>">
                                                <?php echo inspector_e($r['priority']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo !empty($r['target_date']) ? date('M d, Y', strtotime($r['target_date'])) : '—'; ?></td>
                                        <td><?php echo inspector_e($r['status']); ?></td>
                                        <td><?php echo nl2br(inspector_e($r['notes'])); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <footer class="footer-custom">
            <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
            <span>Unified Rental Registration &amp; Compliance System</span>
        </footer>
    </div>
</div>

<!-- Logout Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #251610; border: 1px solid var(--border-light); color: #fff; border-radius: 16px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-warning" style="font-size: 15px;"><i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="text-light small mb-0">Are you sure you want to log out from your session?</p>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-25 justify-content-center">
                <button type="button" class="btn btn-dark border-secondary px-4 py-2" data-bs-dismiss="modal" style="font-size: 12px;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn btn-danger px-4 py-2" style="font-size: 12px; background: #dc3527; border: none;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobile Sidebar Toggle
    const sidebar = document.getElementById('sidebar');
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-show');
    });

    document.addEventListener('click', function(e) {
        if (window.innerWidth < 992) {
            const toggleBtn = document.getElementById('sidebarToggle');
            if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target) && sidebar.classList.contains('mobile-show')) {
                sidebar.classList.remove('mobile-show');
            }
        }
    });
</script>
</body>
</html>