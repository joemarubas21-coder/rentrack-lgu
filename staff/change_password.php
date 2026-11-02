<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

// ==========================
// STAFF INFORMATION
// ==========================

$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

// ==========================
// HANDLE PASSWORD CHANGE
// ==========================

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid session token. Please refresh the page and try again.';
    } elseif (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all password fields.';
    } elseif (strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New password and confirm password do not match.';
    } else {
        try {
            // Fetch user password gamit ang PDO prepared statement
            $userQuery = $conn->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
            $userQuery->execute([':id' => $staffId]);
            $userData = $userQuery->fetch(PDO::FETCH_ASSOC);

            if ($userData && password_verify($current_password, $userData['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id LIMIT 1");
                
                if ($updateStmt->execute([':password' => $hashed_password, ':id' => $staffId])) {
                    $success = 'Password changed successfully.';
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
            } else {
                $error = 'Current password is incorrect.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . safe_error($e);
        }
    }
}

// ==========================
// NOTIFICATIONS & DATA (PDO Safe)
// ==========================

$notificationCount = 0;
try {
    $notifQuery = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE (user_id = :staff_id OR user_id = '0' OR user_id IS NULL) AND is_read = '0'");
    $notifQuery->execute([':staff_id' => $staffId]);
    $row = $notifQuery->fetch(PDO::FETCH_ASSOC);
    $notificationCount = (int) ($row['total'] ?? 0);
} catch (PDOException $e) {
    $notificationCount = 0;
}

$notifications = [];
try {
    $notifListQuery = $conn->prepare("
        SELECT id, title, message, created_at, is_read 
        FROM notifications 
        WHERE (user_id = :staff_id OR user_id = '0' OR user_id IS NULL) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $notifListQuery->execute([':staff_id' => $staffId]);
    $notifications = $notifListQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notifications = [];
}

$user = [];
try {
    $query = $conn->prepare("SELECT * FROM users WHERE id = :staff_id LIMIT 1");
    $query->execute([':staff_id' => $staffId]);
    $user = $query->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $user = [];
}

// Rental & Permit counts
$rentalCount = 0;
try {
    $rentalQuery = $conn->prepare("SELECT COUNT(*) AS total FROM rentals WHERE user_id = :staff_id AND is_deleted = '0'");
    $rentalQuery->execute([':staff_id' => $staffId]);
    $row = $rentalQuery->fetch(PDO::FETCH_ASSOC);
    $rentalCount = (int) ($row['total'] ?? 0);
} catch (PDOException $e) {
    $rentalCount = 0;
}

$pendingRenewalsCount = 0;
try {
    $checkRenewalsTable = $conn->query("SHOW TABLES LIKE 'permit_renewals'");
    if ($checkRenewalsTable && $checkRenewalsTable->fetch(PDO::FETCH_NUM)) {
        $pendRenewalsQuery = $conn->query("SELECT COUNT(*) AS total FROM permit_renewals WHERE status = 'Pending'");
        if ($pendRenewalsQuery) {
            $row = $pendRenewalsQuery->fetch(PDO::FETCH_ASSOC);
            $pendingRenewalsCount = (int) ($row['total'] ?? 0);
        }
    }
} catch (PDOException $e) {
    $pendingRenewalsCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | RENTRACK LGU</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #0a1a12;
            --bg-card: rgba(255, 255, 255, 0.04);
            --border-glass: rgba(255, 255, 255, 0.06);
            --text-primary: #f0fdf4;
            --text-secondary: rgba(255, 255, 255, 0.65);
            --text-muted: rgba(255, 255, 255, 0.35);
            --accent-green-1: #22c55e;
            --accent-green-2: #16a34a;
            --accent-green-light: #86efac;
            --accent-cyan: #2dd4bf;
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
        }

        @keyframes borderSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes textShimmer { 0% { color: #ffffff; } 50% { color: #86efac; } 100% { color: #ffffff; } }

        .animated-logo-wrapper { position: relative; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .animated-logo-ring { position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; border-radius: 50%; border: 2px dashed transparent; border-top-color: var(--accent-green-1); border-bottom-color: var(--accent-cyan); animation: borderSpin 3s linear infinite; }
        .animated-logo-circle { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2)); display: flex; align-items: center; justify-content: center; color: #0a1a12; font-size: 16px; font-weight: 800; z-index: 2; }
        .animated-logo-text { animation: textShimmer 3s infinite ease-in-out; }

        html, body { height: 100%; font-family: 'Inter', sans-serif; background: var(--bg-primary); color: var(--text-primary); overflow-x: hidden; }
        .page-container { display: flex; flex-direction: column; min-height: 100vh; width: 100vw; }
        .official-header { width: 100%; display: flex; align-items: center; justify-content: space-between; background: #052e16; border-bottom: 2px solid var(--accent-green-1); padding: 5px 20px; gap: 20px; position: relative; z-index: 110; }
        .official-header-left, .official-header-right-group { display: flex; align-items: center; gap: 12px; color: #4ade80; font-size: 11px; font-weight: 600; }
        .official-header-badge { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); padding: 2px 10px; border-radius: 20px; display: flex; align-items: center; gap: 6px; }

        .app-body { display: flex; flex: 1; width: 100%; position: relative; }
        .sidebar { width: var(--sidebar-width); background: rgba(10, 20, 16, 0.92); backdrop-filter: blur(20px); border-right: 1px solid var(--border-glass); display: flex; flex-direction: column; padding: 0 12px; flex-shrink: 0; height: 100vh; position: sticky; top: 0; transition: width 0.3s ease; overflow: hidden; z-index: 100; }
        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed); }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; padding: 12px 8px 16px 8px; border-bottom: 1px solid var(--border-glass); margin-bottom: 16px; }
        .sidebar-logo .logo-text h2 { font-size: 16px; font-weight: 800; color: #fff; line-height: 1.2; }
        .sidebar-logo .logo-text span { font-size: 9px; color: var(--accent-green-light); font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; }
        body.sidebar-collapsed .sidebar-logo .logo-text { opacity: 0; width: 0; overflow: hidden; }

        .sidebar-menu { list-style: none; flex: 1; overflow-y: auto; padding: 4px 0; }
        .menu-group-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1.2px; color: var(--text-muted); padding: 12px 14px 6px 14px; font-weight: 700; }
        body.sidebar-collapsed .menu-group-label { opacity: 0; height: 0; padding: 0; overflow: hidden; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 14px; padding: 10px 14px; border-radius: 12px; color: var(--text-secondary); text-decoration: none; font-size: 13px; font-weight: 500; transition: var(--transition); white-space: nowrap; }
        .sidebar-menu li a i { width: 20px; text-align: center; }
        .sidebar-menu li a:hover { background: rgba(255,255,255,0.06); color: #fff; }
        .sidebar-menu li.active a { background: rgba(34, 197, 94, 0.12); color: var(--accent-green-light); border: 1px solid rgba(34, 197, 94, 0.15); }
        .sidebar-menu li.active a i { color: var(--accent-green-1); }
        .sidebar-menu li a .menu-badge { margin-left: auto; background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
        body.sidebar-collapsed .sidebar-menu li a span { opacity: 0; width: 0; overflow: hidden; }

        .sidebar-footer { padding: 12px 0 16px 0; border-top: 1px solid var(--border-glass); }
        .sidebar-footer a { display: flex; align-items: center; gap: 14px; padding: 10px 14px; border-radius: 12px; color: #f87171; text-decoration: none; font-size: 13px; cursor: pointer; }
        body.sidebar-collapsed .sidebar-footer a span { opacity: 0; width: 0; overflow: hidden; }

        .main-wrapper { flex: 1; display: flex; flex-direction: column; min-height: 100vh; overflow: hidden; }
        .top-navbar { position: sticky; top: 0; z-index: 50; display: flex; align-items: center; justify-content: space-between; padding: 12px 28px; background: rgba(10, 20, 16, 0.92); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-glass); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .menu-toggle { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); color: var(--accent-green-light); cursor: pointer; }
        .page-title { font-size: 18px; font-weight: 700; color: #fff; }
        .page-title small { font-size: 12px; color: var(--text-secondary); margin-left: 8px; }

        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .nav-clock { display: flex; flex-direction: column; align-items: flex-end; padding: 4px 16px; border-radius: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(34,197,94,0.08); }
        .nav-clock .time { font-family: monospace; font-size: 14px; font-weight: 700; color: var(--accent-green-1); }
        .nav-clock .date { font-size: 10px; color: var(--accent-green-light); }
        
        .nav-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); color: var(--text-secondary); cursor: pointer; position: relative; text-decoration: none; }
        .nav-icon .badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: #fff; font-size: 9px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        .profile-wrapper { position: relative; }
        .profile-btn { display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px; border-radius: 12px; background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); cursor: pointer; text-decoration: none; }
        .profile-btn .avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2)); display: flex; align-items: center; justify-content: center; color: #0a1a12; font-weight: 800; font-size: 13px; overflow: hidden; }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #fff; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-green-light); text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown { position: absolute; top: calc(100% + 12px); right: 0; width: 220px; background: rgba(10,20,16,0.96); backdrop-filter: blur(24px); border: 1px solid var(--border-glass); border-radius: 14px; padding: 8px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); opacity: 0; visibility: hidden; transition: all 0.25s ease; z-index: 60; }
        .notification-dropdown { width: 360px; padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; }
        .profile-dropdown .dropdown-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px; color: var(--text-secondary); text-decoration: none; font-size: 13px; }
        .profile-dropdown .dropdown-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
        .profile-dropdown .dropdown-item.danger { color: #f87171; }

        .notification-title { padding: 14px 20px; font-weight: 600; font-size: 14px; border-bottom: 1px solid var(--border-glass); color: #fff; }
        .notification-item { padding: 12px 20px; border-bottom: 1px solid rgba(255,255,255,0.04); }
        .notification-item strong { display: block; font-size: 13px; color: var(--accent-green-light); margin-bottom: 2px; }
        .notification-item p { font-size: 12px; color: var(--text-secondary); margin: 0; }

        .content { flex: 1; padding: 28px 32px 20px; overflow-y: auto; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-bottom: 20px; }
        .breadcrumb a { color: var(--text-secondary); text-decoration: none; }
        .breadcrumb strong { color: var(--text-primary); }

        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 28px; }
        .page-header h1 { font-size: 26px; font-weight: 700; }
        .page-header h1 i { color: var(--accent-green-1); margin-right: 10px; }
        .date-badge { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 40px; padding: 8px 18px; font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 8px; }

        .profile-card { background: var(--bg-card); backdrop-filter: blur(12px); border: 1px solid var(--border-glass); border-radius: 20px; box-shadow: 0 8px 28px rgba(0,0,0,0.2); overflow: hidden; margin-bottom: 24px; max-width: 700px; margin-left: auto; margin-right: auto; }
        .profile-header { text-align: center; padding: 32px 24px 20px; border-bottom: 1px solid var(--border-glass); }
        .profile-avatar { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2)); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 30px; color: #0a1a12; }
        .profile-header h2 { font-size: 20px; font-weight: 700; color: #fff; }
        .profile-header p { color: var(--text-secondary); font-size: 13px; margin-top: 4px; }

        .profile-body { padding: 24px 28px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px; }
        .form-group label i { color: var(--accent-green-1); }
        .form-group input { width: 100%; padding: 11px 14px; border-radius: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); color: var(--text-primary); font-size: 14px; outline: none; }
        .form-group input:focus { border-color: rgba(34,197,94,0.4); }

        .profile-actions { display: flex; gap: 12px; flex-wrap: wrap; padding-top: 16px; border-top: 1px solid var(--border-glass); margin-top: 20px; }
        .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; border-radius: 12px; background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2)); color: #0a1a12; font-weight: 600; font-size: 14px; border: none; cursor: pointer; }
        .btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 12px; background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); color: var(--text-secondary); font-size: 14px; text-decoration: none; margin-left: auto; }

        .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-secondary); }
        .footer .brand { background: linear-gradient(135deg, var(--accent-green-1), var(--accent-cyan)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 700; }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>

    <div class="page-container">
        <!-- OFFICIAL HEADER -->
        <div class="official-header">
            <div class="official-header-left">
                <i class="fa-solid fa-landmark"></i>
                <span>REPUBLIC OF THE PHILIPPINES</span>
                <span style="opacity: 0.7;">|</span>
                <span style="font-weight: 400; opacity: 0.8;">Local Government Unit Property &amp; Rental Compliance Portal</span>
            </div>
            <div class="official-header-right-group">
                <div class="official-header-badge">
                    <i class="fa-solid fa-certificate"></i>
                    <span>LGU CERTIFIED PORTAL V2.1</span>
                </div>
                <span>City Treasurer &amp; Rentrack Online System</span>
            </div>
        </div>

        <div class="app-body">
            <!-- SIDEBAR -->
            <aside class="sidebar">
                <div class="sidebar-logo">
                    <div class="animated-logo-wrapper">
                        <div class="animated-logo-ring"></div>
                        <div class="animated-logo-circle">RT</div>
                    </div>
                    <div class="logo-text">
                        <h2 class="animated-logo-text">RENTRACK</h2>
                        <span>LGU Staff</span>
                    </div>
                </div>

                <ul class="sidebar-menu">
                    <li class="menu-group-label">Navigation</li>
                    <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a></li>
                    <li class="menu-group-label">Rental Management</li>
                    <li><a href="rentals.php"><i class="fa-solid fa-folder-open"></i><span>Rental Applications</span></a></li>
                    <li><a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i><span>Register Rental</span></a></li>
                    <li><a href="documents.php"><i class="fa-solid fa-file-lines"></i><span>Documents</span></a></li>
                    <li><a href="renewals_approval.php"><i class="fa-solid fa-file-circle-exclamation"></i><span>Permit Renewals</span><?php if ($pendingRenewalsCount > 0): ?><span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span><?php endif; ?></a></li>
                    <li><a href="reports.php"><i class="fa-solid fa-file-invoice"></i><span>Reports &amp; Analytics</span></a></li>
                    <li class="menu-group-label">Inspection Management</li>
                    <li><a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i><span>My Inspections</span></a></li>
                    <li class="menu-group-label">Financial Management</li>
                    <li><a href="collection.php"><i class="fa-solid fa-cash-register"></i><span>Collections</span></a></li>
                    <li><a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i><span>Payments</span></a></li>
                    <li class="menu-group-label">Account &amp; Settings</li>
                    <li><a href="notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span><?php if ($notificationCount > 0): ?><span class="menu-badge"><?php echo $notificationCount; ?></span><?php endif; ?></a></li>
                    <li class="active"><a href="profile.php"><i class="fa-solid fa-user"></i><span>My Profile</span></a></li>
                </ul>

                <div class="sidebar-footer">
                    <a href="#" onclick="confirmLogout(); return false;">
                        <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
                    </a>
                </div>
            </aside>

            <!-- MAIN WRAPPER -->
            <div class="main-wrapper">
                <header class="top-navbar">
                    <div class="navbar-left">
                        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
                        <div class="page-title">Change Password <small>Secure Your Account</small></div>
                    </div>

                    <div class="navbar-right">
                        <div class="nav-clock">
                            <div class="time" id="clockTime">12:00 PM</div>
                            <div class="date"><?php echo date("M d, Y"); ?></div>
                        </div>
                        
                        <div class="profile-wrapper">
                            <div class="profile-btn" id="profileToggle">
                                <div class="avatar">
                                    <?php if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?php echo $staffInitial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="info">
                                    <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                    <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron"></i>
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

                <main class="content">
                    <div class="breadcrumb">
                        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <a href="profile.php">My Profile</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <strong>Change Password</strong>
                    </div>

                    <div class="page-header">
                        <div>
                            <h1><i class="fa-solid fa-key"></i> Change Password</h1>
                            <p>Update your account security credentials</p>
                        </div>
                        <div class="date-badge"><i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?></div>
                    </div>

                    <?php if ($success): ?>
                        <div style="background:rgba(74,222,128,0.15); border:1px solid rgba(74,222,128,0.3); color:#4ade80; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-weight:600;">
                            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div style="background:rgba(248,113,113,0.15); border:1px solid rgba(248,113,113,0.3); color:#f87171; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-weight:600;">
                            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar"><i class="fa-solid fa-shield-halved"></i></div>
                            <h2><?php echo htmlspecialchars($user['fullname'] ?? $staffName); ?></h2>
                            <p><?php echo ucfirst($user['role'] ?? $staffRole); ?></p>
                        </div>

                        <div class="profile-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="change_password" value="1">

                                <div class="form-group">
                                    <label><i class="fa-solid fa-lock"></i> Current Password</label>
                                    <input type="password" name="current_password" required placeholder="Enter current password">
                                </div>

                                <div class="form-group">
                                    <label><i class="fa-solid fa-key"></i> New Password</label>
                                    <input type="password" name="new_password" required placeholder="At least 6 characters">
                                </div>

                                <div class="form-group">
                                    <label><i class="fa-solid fa-check-double"></i> Confirm New Password</label>
                                    <input type="password" name="confirm_password" required placeholder="Re-enter new password">
                                </div>

                                <div class="profile-actions">
                                    <button type="submit" class="btn-primary">
                                        <i class="fa-solid fa-save"></i> Update Password
                                    </button>
                                    <a href="profile.php" class="btn-back">
                                        <i class="fa-solid fa-arrow-left"></i> Back to Profile
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <footer class="footer">
                        <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                        <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                    </footer>
                </main>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
        });

        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            document.getElementById('clockTime').textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        profileToggle?.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            profileDropdown.classList.toggle('show'); 
        });

        window.addEventListener('click', () => {
            profileDropdown?.classList.remove('show');
        });

        function confirmLogout() {
            Swal.fire({
                title: 'Mag-logout?', text: 'Sigurado ka bang nais mong lumabas?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#22c55e', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Oo, Logout', background: '#0a1a12', color: '#f0fdf4'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });
    </script>
</body>
</html>