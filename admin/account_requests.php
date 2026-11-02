<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   SMTP EMAIL SENDER FUNCTION (CURL SMTPS FOR LOCALHOST)
===================================================== */
function sendGmailNotification($toEmail, $toName, $username, $password, $role, &$debugError = '') {
    $smtpUser = env_value('MAIL_USERNAME', '');
    $smtpPass = env_value('MAIL_PASSWORD', '');

    $subject = "Your RENTRACK LGU Account Request has been Approved!";
    
    $body  = "Hello {$toName},\r\n\r\n";
    $body .= "Good news! Your account request for the role of '" . strtoupper($role) . "' has been approved.\r\n\r\n";
    $body .= "Here are your official login credentials:\r\n";
    $body .= "----------------------------------------\r\n";
    $body .= "Portal Role: " . ucfirst($role) . "\r\n";
    $body .= "Username: {$username}\r\n";
    $body .= "Temporary Password: {$password}\r\n";
    $body .= "----------------------------------------\r\n\r\n";
    $body .= "Please log in through your portal and update your password immediately.\r\n\r\n";
    $body .= "Best regards,\r\nRENTRACK LGU Administrator";

    $headers  = "From: RENTRACK LGU Admin <{$smtpUser}>\r\n";
    $headers .= "To: {$toName} <{$toEmail}>\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Date: " . date("r") . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    
    $rawMessage = $headers . $body;

    $ch = curl_init('smtps://smtp.gmail.com:465');
    
    curl_setopt($ch, CURLOPT_USERNAME, $smtpUser);
    curl_setopt($ch, CURLOPT_PASSWORD, $smtpPass);
    curl_setopt($ch, CURLOPT_MAIL_FROM, "<{$smtpUser}>");
    curl_setopt($ch, CURLOPT_MAIL_RCPT, ["<{$toEmail}>"]);
    
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $rawMessage);
    rewind($stream);

    curl_setopt($ch, CURLOPT_INFILE, $stream);
    curl_setopt($ch, CURLOPT_INFILESIZE, strlen($rawMessage));
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $result = curl_exec($ch);

    if (!$result) {
        $debugError = curl_error($ch);
        curl_close($ch);
        fclose($stream);
        return false;
    }

    curl_close($ch);
    fclose($stream);
    return true;
}

/* =====================================================
   ACTION HANDLERS
===================================================== */
$message = "";
$msgType = "";

// Handle Delete All
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    try {
        $conn->exec("DELETE FROM account_requests");
        $message = "All account requests have been permanently deleted.";
        $msgType = "danger";
    } catch (PDOException $e) {
        $message = "Error deleting all requests: " . safe_error($e);
        $msgType = "danger";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $reqId = (int)$_POST['request_id'];
    $action = $_POST['action'];

    $stmt = $conn->prepare("SELECT * FROM account_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $reqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($req) {
        if ($action === 'approve') {
            $cleanName = strtolower(str_replace(' ', '', $req['fullname']));
            $username = $cleanName . rand(10, 99);
            $defaultPassword = 'Lgu@' . bin2hex(random_bytes(6)) . '1'; // random, 17 chars (was guessable 4-digit)
            $hashedPassword = password_hash($defaultPassword, PASSWORD_BCRYPT);

            try {
                $conn->beginTransaction();

                $insStmt = $conn->prepare("
                    INSERT INTO users (fullname, email, username, password, role, status)
                    VALUES (:fullname, :email, :username, :password, :role, 'Active')
                ");
                $insStmt->execute([
                    ':fullname' => $req['fullname'],
                    ':email'    => $req['email'],
                    ':username' => $username,
                    ':password' => $hashedPassword,
                    ':role'     => $req['requested_role']
                ]);

                $upStmt = $conn->prepare("UPDATE account_requests SET status = 'Approved' WHERE id = :id");
                $upStmt->execute([':id' => $reqId]);

                $conn->commit();

                $debugError = '';
                $mailSent = sendGmailNotification($req['email'], $req['fullname'], $username, $defaultPassword, $req['requested_role'], $debugError);

                $message = "Account created! Username: <strong>{$username}</strong> | Password: <strong>{$defaultPassword}</strong>";
                if ($mailSent) {
                    $message .= " <br><span class='badge bg-success'><i class='fa-solid fa-envelope-circle-check me-1'></i> Credentials successfully sent to {$req['email']}</span>";
                } else {
                    $message .= " <br><span class='badge bg-danger text-white'><i class='fa-solid fa-triangle-exclamation me-1'></i> Mail Error: " . htmlspecialchars($debugError) . "</span>";
                }
                $msgType = "success";

            } catch (PDOException $e) {
                $conn->rollBack();
                $message = "Error creating account: " . safe_error($e);
                $msgType = "danger";
            }

        } elseif ($action === 'reject') {
            $upStmt = $conn->prepare("UPDATE account_requests SET status = 'Rejected' WHERE id = :id");
            $upStmt->execute([':id' => $reqId]);
            $message = "Account request rejected.";
            $msgType = "warning";

        } elseif ($action === 'delete') {
            $delStmt = $conn->prepare("DELETE FROM account_requests WHERE id = :id");
            $delStmt->execute([':id' => $reqId]);
            $message = "Account request permanently deleted from the database.";
            $msgType = "danger";
        }
    }
}

/* =====================================================
   FETCH REQUESTS LIST
===================================================== */
$requestsList = [];
$stmt = $conn->query("SELECT * FROM account_requests ORDER BY id DESC");
if ($stmt) {
    $requestsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// User info
$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
<meta name="theme-color" content="#dc2626">
<title>Account Requests | RENTRACK LGU</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

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
        --navbar-height: 62px;
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
        touch-action: manipulation;
    }

    body.drawer-open { overflow: hidden !important; touch-action: none; }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    .swal2-backdrop-show {
        background: rgba(0, 0, 0, 0.4) !important;
        backdrop-filter: blur(4px) !important;
        -webkit-backdrop-filter: blur(4px) !important;
    }

    /* TOP OFFICIAL BAR */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
        display: flex; justify-content: space-between; align-items: center;
        font-size: 11px; font-weight: 600; z-index: 1100; height: var(--topofficial-height);
        color: #fff; overflow: hidden;
    }
    .topbar-left { display: flex; align-items: center; gap: 8px; min-width: 0; overflow: hidden; }
    .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
    .topbar-left strong { font-weight: 800; white-space: nowrap; flex-shrink: 0; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
    .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
    }

    /* MAIN NAVBAR */
    .rt-topbar {
        position: fixed; top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height); z-index: 1050;
        gap: 12px;
    }
    .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; min-width: 0; }
    .rt-topbar-brand {
        display: flex; align-items: center; gap: 10px;
        height: 100%; padding: 0 18px 0 calc(18px + var(--safe-left));
        border-right: 1px solid var(--border-color); flex-shrink: 0;
    }
    .rt-topbar-logo {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 0 12px rgba(220, 38, 38, 0.2); flex-shrink: 0;
    }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); display: block; line-height: 1.15; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

    .menu-toggle {
        display: none; width: 42px; height: 42px; border-radius: 10px;
        align-items: center; justify-content: center;
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--red-accent); cursor: pointer; font-size: 16px;
        flex-shrink: 0; margin-left: 12px;
    }
    .menu-toggle:active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    /* HORIZONTAL NAV MENU */
    .main-nav-bar {
        flex: 1; display: flex; align-items: center; height: 100%;
        min-width: 0; overflow: visible;
    }
    .nav-menu {
        list-style: none; margin: 0; padding: 0;
        display: flex; align-items: center; gap: 2px;
        flex-wrap: nowrap; height: 100%;
    }
    .nav-item { position: relative; height: 100%; display: flex; align-items: center; }
    .nav-link {
        display: flex; align-items: center; gap: 7px;
        padding: 8px 12px; color: var(--text-sub); text-decoration: none;
        font-size: 12.5px; font-weight: 600; border-radius: 8px;
        transition: all 0.15s ease; cursor: pointer; white-space: nowrap;
        background: transparent; border: none; font-family: inherit; height: 40px;
    }
    .nav-link i.nav-ico { color: var(--red-accent); font-size: 13px; width: 16px; text-align: center; }
    .nav-link .chevron { font-size: 9px; margin-left: 2px; transition: transform 0.2s ease; opacity: 0.7; }
    .nav-link:hover { color: var(--red-accent); background: var(--red-soft); }
    .nav-link.active { color: var(--red-accent); background: var(--red-soft); font-weight: 700; }
    .nav-item.open > .nav-link { color: var(--red-accent); background: var(--red-soft); }
    .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

    .nav-dropdown {
        position: absolute; top: calc(100% + 4px); left: 0; min-width: 240px;
        background: #ffffff; border: 1px solid var(--border-color);
        border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        padding: 8px; opacity: 0; visibility: hidden; transform: translateY(8px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        z-index: 200;
    }
    .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    .nav-item.align-right .nav-dropdown { left: auto; right: 0; }
    .nav-dropdown a {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: all 0.15s ease;
    }
    .nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .nav-dropdown a i { width: 16px; color: var(--red-accent); font-size: 14px; text-align: center; flex-shrink: 0; }

    .mobile-drawer-header { display: none; }

    /* LAYOUT */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; padding: 16px 20px 30px; }
    .sidebar-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
        z-index: 1040; -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
        opacity: 0; transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    /* PAGE HEADER */
    .page-header-card {
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        display: flex; justify-content: space-between; align-items: center; gap: 16px;
        flex-wrap: wrap; box-shadow: var(--shadow-soft);
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; margin: 0;
        display: flex; align-items: center; gap: 10px; font-size: 20px; color: #9f1239;
    }
    .page-header-card h2 i { color: #e11d48; background: rgba(225, 29, 72, 0.1); padding: 8px; border-radius: 10px; font-size: 16px; }
    .page-header-card p { margin: 4px 0 0; font-size: 12px; color: #881337; font-weight: 500; }

    .btn-rt-secondary {
        background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
        padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

    /* TABLE CARD */
    .table-card-rt {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 18px; overflow: hidden; box-shadow: var(--shadow-soft);
    }
    .table-header-rt {
        padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; flex-wrap: wrap;
    }
    .table-header-rt h5 {
        margin: 0; font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 15px;
        color: var(--text-main); display: flex; align-items: center; gap: 8px;
    }
    .table-header-rt h5 i { color: var(--red-accent); }

    table.rt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.rt-table th {
        background: #f8fafc; color: var(--text-muted);
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px; padding: 12px 16px;
        border-bottom: 1px solid var(--border-color); text-align: left; white-space: nowrap;
    }
    table.rt-table td {
        padding: 12px 16px; border-bottom: 1px solid var(--border-color);
        vertical-align: middle; color: var(--text-main);
    }
    table.rt-table tr:hover { background: #f8fafc; }

    /* BADGES */
    .badge-status {
        padding: 4px 10px; border-radius: 999px; font-size: 10px;
        font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px;
    }
    .badge-pending { background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.25); }
    .badge-approved { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
    .badge-rejected { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }

    .badge-role {
        display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
        border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase;
        background: rgba(100, 116, 139, 0.1); color: #475569;
        border: 1px solid rgba(100, 116, 139, 0.2);
    }

    /* ACTION BUTTONS */
    .action-group { display: inline-flex; gap: 6px; align-items: center; justify-content: center; }

    .btn-action {
        width: 34px; height: 34px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 9px; font-size: 13px;
        border: 1px solid transparent; cursor: pointer;
        transition: var(--transition); text-decoration: none;
        position: relative; flex-shrink: 0;
    }
    .btn-action:hover { transform: translateY(-2px); }

    .btn-approve { background: rgba(22, 163, 74, 0.12); color: #16a34a; border-color: rgba(22, 163, 74, 0.3); }
    .btn-approve:hover { background: #16a34a; color: #fff; border-color: #16a34a; }

    .btn-reject { background: rgba(220, 38, 38, 0.12); color: #dc2626; border-color: rgba(220, 38, 38, 0.3); }
    .btn-reject:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .btn-view { background: rgba(8, 145, 178, 0.12); color: #0891b2; border-color: rgba(8, 145, 178, 0.3); }
    .btn-view:hover { background: #0891b2; color: #fff; border-color: #0891b2; }

    .btn-delete-icon { background: rgba(220, 38, 38, 0.12); color: #dc2626; border-color: rgba(220, 38, 38, 0.3); }
    .btn-delete-icon:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .btn-delete-all {
        background: #fef2f2; border: 1px solid rgba(220, 38, 38, 0.3);
        color: #dc2626; padding: 7px 14px; border-radius: 10px; font-size: 11.5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: var(--transition);
    }
    .btn-delete-all:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    /* MODAL */
    .modal-content.rt-modal {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 18px; color: var(--text-main); overflow: hidden;
    }
    .modal-header.rt-modal-header {
        background: linear-gradient(135deg, #dc2626, #991b1b);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        color: #fff; padding: 16px 20px;
    }
    .modal-header.rt-modal-header h5 {
        margin: 0; font-family: 'Space Grotesk', sans-serif; font-weight: 800;
        display: flex; align-items: center; gap: 10px; font-size: 16px;
    }
    .modal-body.rt-modal-body { padding: 20px; }
    .modal-footer.rt-modal-footer {
        border-top: 1px solid var(--border-color);
        padding: 12px 20px; background: #f8fafc;
    }
    .modal-footer.rt-modal-footer .btn-outline-light {
        background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
    }
    .modal-footer.rt-modal-footer .btn-outline-light:hover {
        background: var(--bg-card-alt); color: var(--text-main);
    }

    .detail-row {
        display: flex; padding: 12px 0;
        border-bottom: 1px dashed var(--border-color);
        gap: 12px; align-items: flex-start;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px; color: var(--text-muted);
        min-width: 130px; flex-shrink: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .detail-label i { color: var(--red-accent); font-size: 12px; }
    .detail-value {
        font-size: 13px; color: var(--text-main); font-weight: 500;
        word-break: break-word; flex: 1;
    }

    /* FOOTER */
    .footer {
        margin-top: 20px; padding-top: 14px; padding-bottom: var(--safe-bottom);
        border-top: 1px solid var(--border-color);
        display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted);
        flex-wrap: wrap; gap: 8px;
    }

    /* RESPONSIVE — 1400px */
    @media (max-width: 1400px) {
        .nav-link { padding: 8px 10px; font-size: 12px; }
        .nav-link .nav-ico { font-size: 12px; }
    }

    /* MOBILE — 1200px drawer mode */
    @media (max-width: 1200px) {
        .rt-topbar-left { flex-shrink: 1; min-width: 0; }
        .menu-toggle { display: flex; }

        .main-nav-bar {
            position: fixed; top: 0; left: -100%;
            width: 85vw; max-width: 320px;
            height: 100vh; height: 100dvh;
            flex-direction: column; align-items: stretch; justify-content: flex-start;
            padding: 0; z-index: 1090; overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #ffffff; box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            flex: none;
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
            background: #ffffff; color: var(--red-accent);
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
        .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

        .nav-menu {
            flex-direction: column; width: 100%; justify-content: flex-start;
            gap: 4px; padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            height: auto;
        }
        .nav-item { width: 100%; height: auto; display: block; }
        .nav-link {
            padding: 14px 16px; border-radius: 10px;
            justify-content: flex-start; font-size: 14.5px;
            min-height: 48px; width: 100%; height: auto;
        }
        .nav-link .chevron { margin-left: auto; font-size: 11px; }
        .nav-link .nav-ico { font-size: 15px; width: 18px; }

        .nav-dropdown,
        .nav-item.open .nav-dropdown,
        .nav-item.align-right .nav-dropdown {
            position: static; left: auto; right: auto;
            transform: none; opacity: 1; visibility: visible;
            box-shadow: none; border: none;
            max-height: 0; overflow: hidden;
            transition: max-height 0.3s ease, padding 0.2s ease;
            padding: 0 0 0 16px; background: #f8fafc;
            margin-top: 4px; border-radius: 10px; min-width: 0;
        }
        .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
        .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }
    }

    @media (max-width: 992px) {
        .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
        .rt-topbar-brand { border-right: none; }
    }

    @media (max-width: 768px) {
        .main { padding: 12px; }

        .page-header-card { padding: 16px 18px; border-radius: 14px; margin-bottom: 14px; flex-direction: column; align-items: flex-start; }
        .page-header-card h2 { font-size: 16px; }
        .page-header-card h2 i { padding: 6px; font-size: 13px; }
        .page-header-card p { font-size: 11px; }
        .page-header-card > .btn-rt-secondary { width: 100%; justify-content: center; }

        .table-card-rt { border-radius: 14px; }
        .table-header-rt { padding: 12px 14px; }
        .table-header-rt h5 { font-size: 13.5px; }

        /* Table → Cards on Mobile */
        .table-responsive { overflow: visible !important; }

        table.rt-table,
        table.rt-table tbody { display: block; width: 100%; }
        table.rt-table thead { display: none; }
        table.rt-table tbody { padding: 8px; }

        table.rt-table tbody tr {
            display: block; width: 100%; margin: 0 0 10px; padding: 5px 0;
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-soft);
        }
        table.rt-table tbody tr:last-child { margin-bottom: 0; }

        table.rt-table tbody td {
            display: grid; grid-template-columns: minmax(90px, 34%) minmax(0, 66%);
            align-items: center; gap: 8px; width: 100%; min-width: 0;
            padding: 9px 11px; border-bottom: 1px solid var(--border-color);
            font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
        }
        table.rt-table tbody td:last-child { border-bottom: 0; }
        table.rt-table tbody td::before {
            content: attr(data-label); color: var(--text-muted);
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .45px; min-width: 0;
        }
        table.rt-table tbody td > * { min-width: 0; max-width: 100%; }
        table.rt-table .badge-role,
        table.rt-table .badge-status { justify-self: start; width: fit-content; font-size: 9px; padding: 3px 8px; }

        table.rt-table tbody td:last-child {
            display: block; padding: 10px 11px 11px; background: #ffffff;
        }
        table.rt-table tbody td:last-child::before { display: block; margin-bottom: 7px; }

        .action-group {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(38px, 1fr));
            gap: 6px; width: 100%;
        }
        .action-group form { display: contents; }
        .btn-action { width: 100%; min-height: 36px; height: 36px; }

        .btn-delete-all { font-size: 10.5px; padding: 6px 10px; }
        .btn-delete-all .btn-delete-all-text { display: none; }

        .footer { flex-direction: column; align-items: flex-start; font-size: 10px; }

        /* Modal responsive */
        .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
        .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }

        .detail-row { flex-direction: column; gap: 4px; }
        .detail-label { min-width: auto; }

        .swal2-popup {
            width: calc(100vw - 20px) !important; max-width: 600px !important;
            margin: 10px !important; border-radius: 16px !important;
        }
    }

    @media (max-width: 576px) {
        .page-header-card h2 { font-size: 15px; }
        .page-header-card p { font-size: 10.5px; }

        .rt-topbar-brand { padding: 0 12px; }
        .rt-topbar-logo { width: 32px; height: 32px; font-size: 12px; }
        .menu-toggle { width: 38px; height: 38px; font-size: 14px; margin-left: 8px; }

        table.rt-table tbody td { grid-template-columns: minmax(78px, 36%) minmax(0, 64%); font-size: 11px; }
    }

    @media (max-width: 430px) {
        .top-official-bar { font-size: 9px; }
        .topbar-left { max-width: 58%; }
        .topbar-left strong { font-size: 9px; }
        .cert-badge { font-size: 7.5px; padding: 3px 7px; }
    }

    @media (max-width: 380px) {
        table.rt-table tbody td { grid-template-columns: minmax(76px, 36%) minmax(0, 64%); font-size: 10.5px; }
    }

    @media (hover: none) and (pointer: coarse) {
        .btn-action:hover, .nav-link:hover { transform: none; }
    }

    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: .01ms !important;
            transition-duration: .01ms !important;
        }
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
        <span class="portal-title">Local Government Unit Property &amp; Rental Compliance Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
    </div>
</div>

<!-- MAIN NAVBAR -->
<header class="rt-topbar">
    <div class="rt-topbar-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>

        <div class="rt-topbar-brand">
            <div class="rt-topbar-logo">RT</div>
            <div class="rt-topbar-brand-text">
                <strong>RENTRACK</strong>
                <span>LGU ADMIN</span>
            </div>
        </div>

        <!-- HORIZONTAL NAV MENU -->
        <nav class="main-nav-bar" id="mainNavBar">
            <div class="mobile-drawer-header">
                <div class="drawer-brand">
                    <div class="drawer-brand-logo">RT</div>
                    <div class="drawer-brand-text">
                        <h2>RENTRACK</h2>
                        <span>LGU Admin</span>
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
                        <i class="fa-solid fa-building nav-ico"></i> Rentals
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="rentals.php"><i class="fa-solid fa-building"></i> All Rentals</a>
                        <a href="compliance.php"><i class="fa-solid fa-file-shield"></i> Compliance</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link active" data-dropdown>
                        <i class="fa-solid fa-users nav-ico"></i> Users
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="users.php"><i class="fa-solid fa-users"></i> User Management</a>
                        <a href="account_requests.php"><i class="fa-solid fa-user-clock"></i> Account Requests</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                        <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-user-tie nav-ico"></i> Staff
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="staff_accounts.php"><i class="fa-solid fa-user-tie"></i> Staff Accounts</a>
                        <a href="inspector_accounts.php"><i class="fa-solid fa-user-shield"></i> Inspector Accounts</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown align-right">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-chart-simple nav-ico"></i> Reports
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="reports.php"><i class="fa-solid fa-chart-simple"></i> Reports</a>
                        <a href="map_overview.php"><i class="fa-solid fa-map-location-dot"></i> GIS Mapping</a>
                        <a href="activity_logs.php"><i class="fa-solid fa-clock-rotate-left"></i> Audit Logs</a>
                        <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>

    <div class="rt-topbar-right" style="padding-right: calc(18px + var(--safe-right));">
        <a href="users.php" class="btn-rt-secondary">
            <i class="fa-solid fa-arrow-left"></i> <span class="btn-back-text">Back to Users</span>
        </a>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="wrapper">
    <!-- MAIN CONTENT -->
    <div class="main">
        <div class="page-header-card">
            <div style="min-width:0;">
                <h2><i class="fa-solid fa-user-clock"></i> Account Requests</h2>
                <p>Review and approve staff, inspector, or collector account registrations.</p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="table-card-rt">
            <div class="table-header-rt">
                <h5><i class="fa-solid fa-list"></i> Requests List</h5>
                <?php if (!empty($requestsList)): ?>
                    <form method="POST" id="deleteAllForm" style="display:inline;">
                        <input type="hidden" name="delete_all" value="1">
                        <button type="button" class="btn-delete-all" onclick="confirmDeleteAll()">
                            <i class="fa-solid fa-trash-can"></i>
                            <span class="btn-delete-all-text">Delete All</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="rt-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Employee ID</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th style="width: 1%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($requestsList)): ?>
                            <?php foreach ($requestsList as $row): 
                                $rowData = [
                                    'id' => (int)$row['id'],
                                    'fullname' => $row['fullname'] ?? '',
                                    'email' => $row['email'] ?? '',
                                    'employee_id' => $row['employee_id'] ?? '',
                                    'requested_role' => $row['requested_role'] ?? '',
                                    'status' => $row['status'] ?? '',
                                ];
                                $rowJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr>
                                    <td data-label="ID"><strong>#<?php echo $row['id']; ?></strong></td>
                                    <td data-label="Full Name"><strong style="color:var(--text-main);"><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                                    <td data-label="Email"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td data-label="Employee ID"><code style="color:#0891b2; font-family:'IBM Plex Mono',monospace; font-size:11.5px;"><?php echo htmlspecialchars($row['employee_id']); ?></code></td>
                                    <td data-label="Role"><span class="badge-role"><i class="fa-solid fa-user-tag"></i> <?php echo strtoupper(htmlspecialchars($row['requested_role'])); ?></span></td>
                                    <td data-label="Status">
                                        <?php 
                                        $st = strtolower($row['status']);
                                        if ($st === 'pending') echo '<span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>';
                                        elseif ($st === 'approved') echo '<span class="badge-status badge-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>';
                                        else echo '<span class="badge-status badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
                                        ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-group">
                                            <!-- VIEW -->
                                            <button type="button" class="btn-action btn-view" title="View Details"
                                                onclick='showRequestDetails(<?php echo $rowJson; ?>)'>
                                                <i class="fa-solid fa-eye"></i>
                                            </button>

                                            <!-- APPROVE + REJECT (only if pending) -->
                                            <?php if (strtolower($row['status']) === 'pending'): ?>
                                                <form method="POST" style="display:contents;">
                                                    <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="action" value="approve" class="btn-action btn-approve" title="Approve">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                    <button type="submit" name="action" value="reject" class="btn-action btn-reject" title="Reject">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- DELETE (PERMANENT) -->
                                            <button type="button" class="btn-action btn-delete-icon" title="Delete Permanently"
                                                onclick="confirmDeleteSingle(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['fullname'])); ?>')">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-inbox fa-2x mb-2" style="opacity:0.4;"></i><br>
                                    No account requests found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="footer">
            <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
            <span>Account Access &amp; System Operations</span>
        </footer>
    </div>
</div>

<!-- HIDDEN FORM (for Delete Single) -->
<form method="POST" id="hiddenDeleteForm" style="display:none;">
    <input type="hidden" name="request_id" id="hiddenDeleteId" value="">
    <input type="hidden" name="action" value="delete">
</form>

<!-- VIEW DETAILS MODAL -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rt-modal">
            <div class="modal-header rt-modal-header">
                <h5><i class="fa-solid fa-file-lines"></i> Account Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body rt-modal-body">
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-hashtag"></i> Request ID</div>
                    <div class="detail-value" id="md-id">—</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-user"></i> Full Name</div>
                    <div class="detail-value" id="md-fullname">—</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-envelope"></i> Email Address</div>
                    <div class="detail-value" id="md-email">—</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-id-badge"></i> Employee ID</div>
                    <div class="detail-value" id="md-empid">—</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-user-tag"></i> Requested Role</div>
                    <div class="detail-value" id="md-role">—</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label"><i class="fa-solid fa-circle-info"></i> Current Status</div>
                    <div class="detail-value" id="md-status">—</div>
                </div>
            </div>
            <div class="modal-footer rt-modal-footer">
                <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    /* ============ DRAWER / NAV MENU ============ */
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

    /* ============ VIEW DETAILS MODAL ============ */
    const viewModal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));

    function showRequestDetails(data) {
        document.getElementById('md-id').textContent = '#' + data.id;
        document.getElementById('md-fullname').textContent = data.fullname || '—';
        document.getElementById('md-email').textContent = data.email || '—';
        document.getElementById('md-empid').innerHTML = '<code style="color:#0891b2; font-family:\'IBM Plex Mono\', monospace;">' + (data.employee_id || '—') + '</code>';
        document.getElementById('md-role').innerHTML = '<span class="badge-role"><i class="fa-solid fa-user-tag"></i> ' + String(data.requested_role || '').toUpperCase() + '</span>';

        const st = String(data.status || '').toLowerCase();
        let badge = '';
        if (st === 'pending')  badge = '<span class="badge-status badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>';
        else if (st === 'approved') badge = '<span class="badge-status badge-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>';
        else badge = '<span class="badge-status badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';

        document.getElementById('md-status').innerHTML = badge;
        viewModal.show();
    }

    /* ============ DELETE SINGLE ============ */
    function confirmDeleteSingle(id, name) {
        Swal.fire({
            title: 'Permanently Delete?',
            html: `
                <div style="text-align:center; font-size:13px; color:#334155; line-height:1.6;">
                    You are about to delete the request from<br>
                    <strong style="color:#dc2626;">${name}</strong> (Request #${id}).<br><br>
                    <span style="font-size:11px; color:#64748b;">⚠️ This CANNOT be undone!</span>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash"></i> Yes, Delete',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('hiddenDeleteId').value = id;
                document.getElementById('hiddenDeleteForm').submit();
            }
        });
    }

    /* ============ DELETE ALL ============ */
    function confirmDeleteAll() {
        Swal.fire({
            title: '🚨 Delete ALL Requests?',
            html: `
                <div style="text-align:center; font-size:13px; color:#334155; line-height:1.6;">
                    This will <strong style="color:#dc2626;">PERMANENTLY DELETE ALL</strong> account requests<br>
                    from the database.<br><br>
                    <span style="font-size:11px; color:#d97706;">⚠️ This action CANNOT be undone!</span>
                </div>
            `,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Yes, Delete All',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('deleteAllForm').submit();
            }
        });
    }
</script>
</body>
</html>