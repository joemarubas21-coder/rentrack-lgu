<?php
// register.php
require_once __DIR__ . '/config/session.php'; app_session_start();

// Import Supabase/PDO DB Connection
require_once 'config/db.php';

// Import Activity Logger (PostgreSQL/PDO Compatible)
require_once 'config/logger.php';

// Import PHPMailer Classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Direktang i-include ang PHPMailer classes mula sa vendor folder
require_once __DIR__ . '/vendor/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer.php';
require_once __DIR__ . '/vendor/SMTP.php';

$message = '';
$registration_success = false;
$registered_email = '';
$registered_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Form Validations
    if (empty($fullname) || empty($username) || empty($email) || empty($contact) || empty($password)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Please fill in all required fields.</div>';
    } 
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Please enter a valid email address.</div>';
    }
    elseif (!preg_match('/[0-9]/', $username)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Username must contain at least one number (0-9).</div>';
    }
    elseif ($password !== $confirm_password) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Password and confirm password do not match.</div>';
    } 
    elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Password does not meet security requirements.</div>';
    } 
    else {
        try {
            // Check existing username in Supabase
            $check_user = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1");
            $check_user->execute([':username' => $username]);
            
            // Check existing email at kunin kung verified na
            $check_email = $conn->prepare("SELECT id, is_verified FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $check_email->execute([':email' => $email]);
            $existing_email_user = $check_email->fetch(PDO::FETCH_ASSOC);

            // BAGONG LOGIC DITO
            if ($check_user->fetch()) {
                // I-check kung ang username ay pagmamay-ari ng existing email na hindi pa verified (para payagan siyang mag-update ng username)
                $is_own_unverified_username = false;
                if ($existing_email_user) {
                    $check_own_username = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND id = :id LIMIT 1");
                    $check_own_username->execute([':username' => $username, ':id' => $existing_email_user['id']]);
                    if ($check_own_username->fetch()) {
                        $is_own_unverified_username = true;
                    }
                }

                if (!$is_own_unverified_username) {
                    $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> The username <strong>'.htmlspecialchars($username).'</strong> is already taken.</div>';
                }
            } 

            // Kung existing ang email PERO VERIFIED NA, doon lang natin harangin
            if (empty($message) && $existing_email_user && (int)$existing_email_user['is_verified'] === 1) {
                $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> The email address <strong>'.htmlspecialchars($email).'</strong> is already registered.</div>';
            } 

            // Kung wala pang error sa itaas, proceed sa save/update
            if (empty($message)) {
                // Hash Password & Generate Verification Token
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));
                $role = 'owner';
                $status = 'Active';
                $is_verified = 0;  // Needs email confirmation

                if ($existing_email_user) {
                    // UPDATE EXISTING UNVERIFIED RECORD
                    $existing_id = $existing_email_user['id'];
                    $insert_stmt = $conn->prepare("
                        UPDATE users 
                        SET fullname = :fullname, username = :username, contact = :contact, 
                            password = :password, verification_token = :token, is_verified = 0, status = :status 
                        WHERE id = :id
                    ");
                    $execute_success = $insert_stmt->execute([
                        ':fullname'   => $fullname,
                        ':username'   => $username,
                        ':contact'    => $contact,
                        ':password'   => $hashed_password,
                        ':token'      => $token,
                        ':status'     => $status,
                        ':id'         => $existing_id
                    ]);
                    $newUserId = $existing_id;
                } else {
                    // INSERT NEW RECORD
                    $insert_stmt = $conn->prepare("
                        INSERT INTO users (fullname, username, email, contact, password, role, status, verification_token, is_verified) 
                        VALUES (:fullname, :username, :email, :contact, :password, :role, :status, :token, :is_verified)
                    ");
                    $execute_success = $insert_stmt->execute([
                        ':fullname'   => $fullname,
                        ':username'   => $username,
                        ':email'      => $email,
                        ':contact'    => $contact,
                        ':password'   => $hashed_password,
                        ':role'       => $role,
                        ':status'     => $status,
                        ':token'      => $token,
                        ':is_verified'=> $is_verified
                    ]);
                    $newUserId = (int) $conn->lastInsertId();
                }

                if ($execute_success) {
                    // ✅ I-LOG ANG REGISTRATION SA ACTIVITY LOGS
                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            $newUserId,
                            "Owner registration (re-registered): {$fullname} ({$username})",
                            'owner',
                            $_SERVER['REMOTE_ADDR'] ?? null
                        );
                    }

                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $verify_link = site_base_url() . "/verify.php?token=" . $token;

                    // IPADALA ANG EMAIL GAMIT ANG PHPMAILER GMAIL SMTP
                    $mail = new PHPMailer(true);

                    try {
                        // Server settings
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = env_value('MAIL_USERNAME', ''); 
                        $mail->Password   = env_value('MAIL_PASSWORD', '');   
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 443; // <--- BINAGO SA 443

                        // Recipients
                        $mail->setFrom(env_value('MAIL_USERNAME', ''), 'RENTRACK LGU');
                        $mail->addAddress($email, $fullname);

                        // Content
                        $mail->isHTML(true);
                        $mail->Subject = 'Confirm your RENTRACK LGU Account';
                        $mail->Body    = "
                        <html>
                        <body style='font-family: Arial, sans-serif; background-color: #f8fafc; color: #0f172a; padding: 20px;'>
                            <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                                <h2 style='color: #dc2626;'>Greetings, {$fullname}!</h2>
                                <p>Thank you for registering on the <strong>RENTRACK LGU Owner Portal</strong>.</p>
                                <p>To activate your account, please click the verification button below:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$verify_link}' style='background-color: #dc2626; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Verify Email Address</a>
                                </div>
                                <p>If you did not request this, please ignore this email.</p>
                            </div>
                        </body>
                        </html>";

                        $mail->send();
                        $registration_success = true;
                        $registered_email = $email;
                        $registered_username = $username;
                    } catch (Exception $e) {
                        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Could not send email. Mailer Error: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
                    }
                } else {
                    $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> An error occurred while saving. Please try again.</div>';
                }
            }
        } catch (PDOException $e) {
            $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Database Error: ' . htmlspecialchars(safe_error($e)) . '</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
  <meta name="theme-color" content="#f8fafc">
  <meta name="format-detection" content="telephone=no">
  <title>Create Account · RENTRACK LGU</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <style>
    :root{
      --bg-body:#f8fafc;
      --bg-card:#ffffff;
      --bg-card-alt:#f1f5f9;
      --red-accent:#dc2626;
      --red-hover:#ef4444;
      --red-soft:#fee2e2;
      --gold:#d97706;
      --teal:#0891b2;
      --emerald:#059669;
      --violet:#7c3aed;
      --text-main:#0f172a;
      --text-sub:#334155;
      --text-muted:#64748b;
      --border-color:#e2e8f0;
      --border-strong:#cbd5e1;
      --shadow-soft:0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1);
      --shadow-lift:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
      --shadow-lg:0 20px 40px -10px rgba(0,0,0,0.15);

      --pad-x:12px;
      --pad-y:10px;
      --shell-radius:18px;
      --field-h:44px;
      --field-fs:13.5px;

      --safe-t:env(safe-area-inset-top, 0px);
      --safe-r:env(safe-area-inset-right, 0px);
      --safe-b:env(safe-area-inset-bottom, 0px);
      --safe-l:env(safe-area-inset-left, 0px);
    }

    *,*::before,*::after{
      box-sizing:border-box;
      margin:0;
      padding:0;
      -webkit-tap-highlight-color:transparent;
    }

    html{
      min-height:100%;
      scroll-behavior:smooth;
      -webkit-text-size-adjust:100%;
      text-size-adjust:100%;
    }

    body{
      min-height:100vh;
      min-height:100dvh;
      width:100%;
      font-family:'Inter',sans-serif;
      color:var(--text-main);
      background-color:var(--bg-body);
      display:flex;
      flex-direction:column;
      align-items:stretch;
      justify-content:flex-start;
      padding:
        calc(var(--pad-y) + var(--safe-t))
        calc(var(--pad-x) + var(--safe-r))
        calc(var(--pad-y) + var(--safe-b))
        calc(var(--pad-x) + var(--safe-l));
      overflow-x:hidden;
      position:relative;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    .page{
      width:min(1000px,100%);
      max-width:100%;
      position:relative;
      z-index:1;
      margin:auto;
    }

    .register-shell{
      display:grid;
      grid-template-columns:minmax(0,320px) minmax(0,1fr);
      min-height:0;
      background:#ffffff;
      border:1px solid var(--border-color);
      border-radius:var(--shell-radius);
      overflow:hidden;
      box-shadow:var(--shadow-lg);
    }

    .brand-panel,
    .form-panel{ min-width:0; }

    /* ===== LEFT PANEL: RED (TUGMA SA SIDEBAR NG DASHBOARD) ===== */
    .brand-panel{
      padding:32px 26px;
      position:relative;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      gap:18px;
      background:linear-gradient(160deg, #dc2626 0%, #991b1b 100%);
      color:#ffffff;
      border-right:1px solid rgba(255,255,255,.10);
    }

    .brand-top{
      display:flex;
      align-items:center;
      gap:10px;
    }

    .brand-mark{
      width:44px;
      height:44px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#dc2626;
      background:#ffffff;
      box-shadow:0 8px 20px rgba(0,0,0,.15);
      font-size:20px;
      flex-shrink:0;
    }

    .brand-name{
      font-family:'Space Grotesk',sans-serif;
      font-weight:800;
      letter-spacing:-.5px;
      font-size:18px;
      line-height:1.2;
      color:#ffffff;
    }

    .brand-name span{ color:#fecdd3; }

    .brand-content{ margin-top:28px; }

    .eyebrow{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:5px 11px;
      border:1px solid rgba(255,255,255,.30);
      border-radius:999px;
      background:rgba(255,255,255,.12);
      color:#ffffff;
      font-size:10px;
      font-weight:700;
      letter-spacing:.6px;
      text-transform:uppercase;
    }

    .brand-content h1{
      margin-top:14px;
      font-family:'Space Grotesk',sans-serif;
      font-size:clamp(20px,2.4vw,28px);
      line-height:1.15;
      letter-spacing:-.8px;
      color:#ffffff;
    }

    .brand-content h1 span{ color:#fecdd3; }

    .brand-content p{
      color:rgba(255,255,255,.85);
      font-size:12.5px;
      line-height:1.65;
      margin-top:12px;
      max-width:100%;
    }

    .feature-list{
      list-style:none;
      margin-top:22px;
      display:grid;
      gap:10px;
    }

    .feature-list li{
      display:flex;
      align-items:center;
      gap:10px;
      color:rgba(255,255,255,.95);
      font-size:12px;
    }

    .feature-icon{
      width:28px;
      height:28px;
      flex:0 0 28px;
      border-radius:8px;
      display:grid;
      place-items:center;
      color:#ffffff;
      background:rgba(255,255,255,.15);
      border:1px solid rgba(255,255,255,.20);
      font-size:11px;
    }

    .brand-bottom{
      color:rgba(255,255,255,.65);
      font-size:10.5px;
      line-height:1.5;
    }

    /* ===== RIGHT PANEL: FORM ===== */
    .form-panel{
      padding:32px 34px 26px;
      background:#ffffff;
    }

    .form-heading{ margin-bottom:20px; }

    .form-heading h2{
      font-family:'Space Grotesk',sans-serif;
      font-size:clamp(20px,2vw,24px);
      letter-spacing:-.6px;
      line-height:1.2;
      color:var(--text-main);
      font-weight:800;
    }

    .form-heading p{
      color:var(--text-muted);
      margin-top:6px;
      font-size:13px;
      line-height:1.45;
    }

    .progress-wrap{ margin-bottom:20px; }

    .progress-top{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      margin-bottom:8px;
    }

    .progress-title{
      font-size:10.5px;
      color:var(--text-muted);
      font-weight:700;
      letter-spacing:.4px;
      text-transform:uppercase;
    }

    .progress-label{
      font-size:11px;
      color:var(--text-sub);
      font-weight:700;
      white-space:nowrap;
    }

    .progress-track{
      height:6px;
      display:flex;
      gap:4px;
    }

    .progress-seg{
      flex:1;
      border-radius:999px;
      background:var(--bg-card-alt);
      border:1px solid var(--border-color);
      transition:background .25s ease, box-shadow .25s ease, border-color .25s ease;
    }

    .progress-seg.filled{
      background:linear-gradient(90deg, var(--red-accent), #ef4444);
      border-color:var(--red-accent);
      box-shadow:0 0 12px rgba(220,38,38,.20);
    }

    .alert{
      display:flex;
      align-items:flex-start;
      gap:8px;
      padding:12px 14px;
      border-radius:10px;
      margin-bottom:16px;
      font-size:12.5px;
      line-height:1.5;
      overflow-wrap:anywhere;
      word-break:break-word;
    }

    .alert i{ flex-shrink:0; margin-top:2px; }

    .alert.error{
      color:#991b1b;
      background:#fee2e2;
      border:1px solid #fecaca;
    }

    .alert.success{
      color:#065f46;
      background:#d1fae5;
      border:1px solid #a7f3d0;
    }

    .alert.warning{
      color:#92400e;
      background:#fef3c7;
      border:1px solid #fde68a;
    }

    .form-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:14px 18px;
    }

    .form-group{ min-width:0; }

    label{
      display:flex;
      align-items:center;
      gap:6px;
      color:var(--text-sub);
      font-size:11.5px;
      font-weight:700;
      margin-bottom:6px;
      letter-spacing:.1px;
      line-height:1.3;
    }

    label i{
      color:var(--red-accent);
      width:14px;
      text-align:center;
      flex-shrink:0;
      font-size:11px;
    }

    .input-wrap{ position:relative; }

    .input-wrap > .field-icon{
      position:absolute;
      left:14px;
      top:50%;
      transform:translateY(-50%);
      color:var(--text-muted);
      font-size:13px;
      pointer-events:none;
      transition:color .2s ease;
    }

    input{
      width:100%;
      min-height:var(--field-h);
      padding:11px 42px 11px 40px;
      border:1px solid var(--border-color);
      border-radius:10px;
      outline:none;
      background:var(--bg-card-alt);
      color:var(--text-main);
      font-family:inherit;
      font-size:var(--field-fs);
      line-height:1.35;
      transition:border-color .2s ease, background .2s ease, box-shadow .2s ease;
      touch-action:manipulation;
      -webkit-appearance:none;
      appearance:none;
    }

    input::placeholder{ color:#94a3b8; }

    input:hover{
      border-color:var(--border-strong);
      background:#ffffff;
    }

    input:focus{
      border-color:var(--red-accent);
      background:#ffffff;
      box-shadow:0 0 0 3px rgba(220,38,38,.10);
    }

    input:focus + .toggle-pw,
    .input-wrap:focus-within .field-icon{
      color:var(--red-accent);
    }

    input:-webkit-autofill,
    input:-webkit-autofill:hover,
    input:-webkit-autofill:focus{
      -webkit-text-fill-color:var(--text-main);
      -webkit-box-shadow:0 0 0 1000px #ffffff inset;
      box-shadow:0 0 0 1000px #ffffff inset;
      transition:background-color 9999s ease-out 0s;
    }

    .toggle-pw{
      position:absolute;
      right:6px;
      top:50%;
      transform:translateY(-50%);
      width:34px;
      height:34px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:var(--text-muted);
      cursor:pointer;
      transition:background .2s ease, color .2s ease;
      display:grid;
      place-items:center;
      touch-action:manipulation;
    }

    .toggle-pw:hover{
      background:var(--bg-card-alt);
      color:var(--text-main);
    }

    .hint-text{
      min-height:14px;
      margin-top:4px;
      font-size:10.5px;
      line-height:1.4;
      overflow-wrap:anywhere;
    }

    .hint-text.match{color:var(--emerald);font-weight:600}
    .hint-text.no-match{color:#dc2626;font-weight:600}

    .username-rule{
      display:flex;
      align-items:flex-start;
      gap:4px;
      min-height:14px;
      margin-top:3px;
      color:var(--text-muted);
      font-size:10px;
      line-height:1.4;
    }

    .username-rule i{
      font-size:8px;
      color:var(--text-muted);
      margin-top:3px;
      flex-shrink:0;
    }

    .username-rule.valid{ color:var(--emerald); }
    .username-rule.valid i{ color:var(--emerald); }
    .username-rule.invalid{ color:#dc2626; }
    .username-rule.invalid i{ color:#dc2626; }

    .strength-meter{
      height:4px;
      background:var(--bg-card-alt);
      border-radius:999px;
      margin-top:8px;
      overflow:hidden;
      border:1px solid var(--border-color);
    }

    .strength-bar{
      height:100%;
      width:0%;
      background:var(--red-accent);
      transition:width .3s ease, background .3s ease;
      border-radius:999px;
    }

    .req-box{
      display:none;
      background:var(--bg-card-alt);
      border:1px solid var(--border-color);
      border-radius:10px;
      padding:10px 12px;
      margin-top:8px;
    }

    .req-title{
      font-size:9.5px;
      font-weight:700;
      color:var(--text-muted);
      letter-spacing:.4px;
      text-transform:uppercase;
      margin-bottom:6px;
    }

    .req-list{
      list-style:none;
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .req-list li{
      font-size:10.5px;
      color:var(--text-muted);
      display:flex;
      align-items:flex-start;
      gap:6px;
      line-height:1.35;
      transition:color .2s ease;
    }

    .req-list li i{
      font-size:9px;
      width:11px;
      text-align:center;
      margin-top:2px;
      flex-shrink:0;
    }

    .req-list li.met{ color:var(--emerald); font-weight:600; }
    .req-list li.met i{ color:var(--emerald); }

    .terms{
      display:flex;
      align-items:flex-start;
      gap:8px;
      margin-top:18px;
      color:var(--text-muted);
      font-size:11px;
      line-height:1.5;
      padding:10px 12px;
      background:var(--bg-card-alt);
      border-radius:10px;
      border:1px solid var(--border-color);
    }

    .terms i{
      color:var(--red-accent);
      margin-top:2px;
      flex-shrink:0;
      font-size:12px;
    }

    .btn-submit{
      width:100%;
      min-height:48px;
      margin-top:16px;
      padding:12px 16px;
      border:0;
      border-radius:11px;
      background:linear-gradient(135deg, var(--red-accent), #991b1b);
      color:#ffffff;
      font-family:inherit;
      font-size:14px;
      font-weight:800;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      box-shadow:0 8px 20px rgba(220,38,38,.25);
      transition:transform .2s ease, box-shadow .2s ease, opacity .2s ease;
      touch-action:manipulation;
      -webkit-appearance:none;
      appearance:none;
      letter-spacing:.2px;
    }

    .btn-submit:hover{
      transform:translateY(-2px);
      box-shadow:0 12px 28px rgba(220,38,38,.35);
      background:linear-gradient(135deg, #ef4444, #dc2626);
    }

    .btn-submit:active{ transform:translateY(0); }

    .btn-submit:disabled{
      cursor:wait;
      opacity:.75;
      transform:none;
    }

    .footer-link{
      text-align:center;
      margin-top:16px;
      padding:14px 0 4px;
      border-top:1px solid var(--border-color);
      color:var(--text-muted);
      font-size:12px;
      line-height:1.6;
      display:block;
      width:100%;
    }

    .footer-link a{
      color:var(--red-accent);
      text-decoration:none;
      font-weight:700;
      display:inline-block;
      padding:3px 5px;
      border-radius:6px;
      transition:background .2s ease;
    }

    .footer-link a:hover{
      text-decoration:underline;
      background:var(--red-soft);
    }

    .modal-overlay{
      position:fixed;
      inset:0;
      background:rgba(15,23,42,.55);
      backdrop-filter:blur(8px);
      -webkit-backdrop-filter:blur(8px);
      display:flex;
      align-items:center;
      justify-content:center;
      z-index:1000;
      opacity:0;
      visibility:hidden;
      transition:opacity .3s ease, visibility .3s ease;
      padding:
        calc(14px + var(--safe-t))
        calc(14px + var(--safe-r))
        calc(14px + var(--safe-b))
        calc(14px + var(--safe-l));
      overflow-y:auto;
      -webkit-overflow-scrolling:touch;
    }

    .modal-overlay.active{
      opacity:1;
      visibility:visible;
    }

    .modal-card{
      background:#ffffff;
      border:1px solid var(--border-color);
      border-radius:16px;
      width:min(420px,100%);
      max-height:calc(100dvh - 28px);
      overflow-y:auto;
      -webkit-overflow-scrolling:touch;
      padding:28px 22px;
      text-align:center;
      box-shadow:var(--shadow-lg);
      transform:scale(.92);
      transition:transform .3s ease;
      margin:auto;
    }

    .modal-overlay.active .modal-card{ transform:scale(1); }

    .modal-icon{
      width:52px;
      height:52px;
      background:var(--red-soft);
      border:1px solid #fecaca;
      border-radius:50%;
      display:grid;
      place-items:center;
      color:var(--red-accent);
      font-size:22px;
      margin:0 auto 16px;
    }

    .modal-icon.success{
      background:#d1fae5;
      border-color:#a7f3d0;
      color:var(--emerald);
    }

    .modal-card h3{
      font-family:'Space Grotesk',sans-serif;
      font-size:19px;
      margin-bottom:10px;
      line-height:1.3;
      color:var(--text-main);
      font-weight:800;
    }

    .modal-card p{
      color:var(--text-sub);
      font-size:13px;
      line-height:1.55;
      margin-bottom:18px;
      overflow-wrap:anywhere;
    }

    .modal-card .target-email{
      color:var(--red-accent);
      font-weight:700;
      word-break:break-all;
    }

    .modal-actions{
      display:flex;
      gap:10px;
    }

    .btn-modal{
      flex:1;
      min-height:44px;
      padding:10px 14px;
      border-radius:9px;
      font-family:inherit;
      font-size:13px;
      font-weight:700;
      cursor:pointer;
      border:0;
      transition:background .2s ease, opacity .2s ease, transform .2s ease;
      touch-action:manipulation;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
    }

    .btn-cancel{
      background:var(--bg-card-alt);
      color:var(--text-main);
      border:1px solid var(--border-color);
    }

    .btn-cancel:hover{ background:#e2e8f0; }

    .btn-confirm{
      background:linear-gradient(135deg, var(--red-accent), #991b1b);
      color:#ffffff;
      box-shadow:0 6px 16px rgba(220,38,38,.25);
    }

    .btn-confirm:hover{
      opacity:.95;
      transform:translateY(-1px);
      box-shadow:0 8px 20px rgba(220,38,38,.35);
    }

    .resend-link{
      display:block;
      margin-top:12px;
      font-size:11.5px;
      color:var(--text-muted);
      text-decoration:none;
    }

    .resend-link a{
      color:var(--red-accent);
      font-weight:700;
    }

    .resend-link a:hover{ text-decoration:underline; }

    /* ===== RESPONSIVE ===== */
    @media (min-width:1440px){
      .page{ width:min(1080px,100%); }
      .register-shell{ grid-template-columns:minmax(0,360px) minmax(0,1fr); }
      .brand-panel{ padding:38px 32px; }
      .form-panel{ padding:38px 40px 32px; }
    }

    @media (max-width:1100px){
      .register-shell{ grid-template-columns:minmax(0,300px) minmax(0,1fr); }
      .brand-panel{ padding:28px 22px; }
      .brand-content{ margin-top:22px; }
      .form-panel{ padding:28px 28px 24px; }
    }

    @media (max-width:900px){
      :root{
        --pad-x:10px;
        --pad-y:10px;
        --shell-radius:16px;
      }

      body{ align-items:flex-start; }

      .register-shell{
        grid-template-columns:minmax(0,1fr);
        min-height:0;
      }

      .brand-panel{
        padding:24px 20px;
        border-right:0;
        gap:16px;
      }

      .brand-content{ margin-top:16px; }

      .feature-list{
        grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
        gap:10px;
        margin-top:16px;
      }

      .brand-bottom{ display:none; }

      .form-panel{ padding:24px 20px calc(40px + var(--safe-b)); }
    }

    @media (max-width:700px){
      .form-grid{
        grid-template-columns:minmax(0,1fr);
        gap:12px;
      }

      .brand-panel{ padding:20px 16px; }

      .form-panel{ padding:20px 16px calc(40px + var(--safe-b)); }

      .brand-mark{ width:40px; height:40px; border-radius:11px; font-size:17px; }
      .brand-name{ font-size:16px; }

      .brand-content{ margin-top:14px; }
      .brand-content p{ font-size:12px; line-height:1.55; }

      .feature-list{ grid-template-columns:1fr; gap:8px; margin-top:14px; }
      .feature-list li{ font-size:11.5px; }

      .form-heading{ margin-bottom:16px; }
      .form-heading p{ font-size:12.5px; }

      .progress-wrap{ margin-bottom:16px; }

      .btn-submit{ min-height:48px; font-size:14px; }
    }

    @media (max-width:430px){
      :root{
        --pad-x:6px;
        --pad-y:8px;
        --shell-radius:14px;
        --field-h:46px;
        --field-fs:16px;
      }

      .register-shell{ margin:2px 0; }

      .brand-panel{ padding:18px 14px; }

      .form-panel{ padding:18px 14px calc(40px + var(--safe-b)); }

      .brand-top{ gap:8px; }
      .brand-mark{ width:38px; height:38px; border-radius:10px; font-size:16px; }
      .brand-name{ font-size:15px; }

      .eyebrow{ font-size:9.5px; padding:4px 9px; }

      .brand-content h1{
        font-size:19px;
        letter-spacing:-.5px;
        margin-top:12px;
      }

      .form-heading h2{ font-size:18px; }
      .form-heading p{ font-size:12px; }

      .progress-title{ font-size:10px; }
      .progress-label{ font-size:10.5px; }

      label{ font-size:11px; }
      .alert{ font-size:11.5px; padding:10px 12px; }

      .input-wrap > .field-icon{ left:12px; font-size:12px; }
      input{ padding:11px 42px 11px 38px; }

      .toggle-pw{ right:4px; width:36px; height:36px; }

      .req-box{ padding:9px 11px; }
      .req-list li{ font-size:10px; }

      .terms{ font-size:10.5px; }

      .modal-card{ padding:22px 18px; border-radius:14px; }
      .modal-card h3{ font-size:17px; }
      .modal-card p{ font-size:12px; }

      .modal-actions{ flex-direction:column; }
      .btn-modal{ width:100%; min-height:46px; }

      .footer-link{ font-size:11.5px; padding:14px 0 6px; }
    }

    @media (max-width:340px){
      :root{ --pad-x:4px; }

      .brand-panel{ padding:14px 12px; }

      .form-panel{ padding:14px 12px calc(40px + var(--safe-b)); }

      .brand-content h1{ font-size:17px; }
      .form-heading h2{ font-size:16px; }
      .brand-content p{ font-size:11px; }

      label{ font-size:10.5px; }
      .progress-track{ gap:3px; }

      .field-icon{ display:none; }
      input{ padding-left:14px; padding-right:40px; }

      .footer-link{ font-size:11px; }
    }

    @media (orientation:landscape) and (max-height:560px){
      body{ align-items:flex-start; }

      .brand-panel{ padding:14px 18px; gap:10px; }

      .brand-content{ margin-top:8px; }
      .brand-content h1{ font-size:17px; margin-top:0; }
      .brand-content p,
      .feature-list{ display:none; }

      .form-panel{ padding:16px 20px calc(40px + var(--safe-b)); }
      .form-heading{ margin-bottom:12px; }

      .modal-card{ padding:18px 16px; }
      .modal-actions{ flex-direction:row; }
    }

    @media (hover:none) and (pointer:coarse){
      input{ font-size:16px; }

      .toggle-pw{ width:38px; height:38px; }

      .btn-submit{ min-height:48px; }
      .btn-modal{ min-height:46px; }

      .btn-submit:hover,
      .toggle-pw:hover{ transform:none; }
    }

    @media (prefers-reduced-motion:reduce){
      html{ scroll-behavior:auto; }

      *,*::before,*::after{
        animation-duration:.001ms !important;
        animation-iteration-count:1 !important;
        transition-duration:.001ms !important;
      }
    }
  </style>
</head>

<body>
  <main class="page">
    <section class="register-shell">

      <aside class="brand-panel">
        <div>
          <div class="brand-top">
            <div class="brand-mark">
              <i class="fa-solid fa-building-columns"></i>
            </div>
            <div class="brand-name">RENTRACK <span>LGU</span></div>
          </div>

          <div class="brand-content">
            <div class="eyebrow">
              <i class="fa-solid fa-shield-halved"></i>
              Owner Portal
            </div>

            <h1>Manage your property with <span>confidence.</span></h1>

            <p>
              Create your RENTRACK LGU owner account and get started with a
              secure and organized rental management experience.
            </p>

            <ul class="feature-list">
              <li>
                <span class="feature-icon"><i class="fa-solid fa-lock"></i></span>
                Secure account registration
              </li>
              <li>
                <span class="feature-icon"><i class="fa-solid fa-envelope-circle-check"></i></span>
                Email verification
              </li>
              <li>
                <span class="feature-icon"><i class="fa-solid fa-chart-line"></i></span>
                Professional property management
              </li>
            </ul>
          </div>
        </div>

        <div class="brand-bottom">
          <i class="fa-regular fa-circle-check"></i>
          Your account information is protected during registration.
        </div>
      </aside>

      <div class="form-panel">

        <div class="form-heading">
          <h2>Create your account</h2>
          <p>Enter your details below to register as a property owner.</p>
        </div>

        <div class="progress-wrap">
          <div class="progress-top">
            <span class="progress-title">Registration Progress</span>
            <span class="progress-label" id="progressLabel">0 of 6 completed</span>
          </div>

          <div class="progress-track" id="progressTrack">
            <div class="progress-seg" data-field="fullname"></div>
            <div class="progress-seg" data-field="username"></div>
            <div class="progress-seg" data-field="email"></div>
            <div class="progress-seg" data-field="contact"></div>
            <div class="progress-seg" data-field="password"></div>
            <div class="progress-seg" data-field="confirm_password"></div>
          </div>
        </div>

        <?php echo $message; ?>

        <?php if (!$registration_success): ?>
        <form method="POST" id="registerForm" novalidate>
          <input type="hidden" name="register" value="1">
          <div class="form-grid">

            <div class="form-group">
              <label for="fullname">
                <i class="fa-regular fa-user"></i>
                Full Name
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-regular fa-user"></i>
                <input
                  type="text"
                  id="fullname"
                  name="fullname"
                  placeholder="Juan Dela Cruz"
                  required
                  autocomplete="name"
                  autocapitalize="words"
                  enterkeyhint="next"
                  value="<?php echo htmlspecialchars($_POST['fullname'] ?? '', ENT_QUOTES); ?>"
                >
              </div>
            </div>

            <div class="form-group">
              <label for="usernameInput">
                <i class="fa-regular fa-circle-user"></i>
                Username
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-solid fa-at"></i>
                <input
                  type="text"
                  name="username"
                  id="usernameInput"
                  placeholder="juan.dc1"
                  required
                  autocomplete="username"
                  autocapitalize="none"
                  autocorrect="off"
                  spellcheck="false"
                  enterkeyhint="next"
                  value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES); ?>"
                >
              </div>
              <div class="hint-text" id="usernameHint"></div>
              <div class="username-rule" id="usernameRule">
                <i class="fa-solid fa-circle-info"></i> Username must contain at least one number.
              </div>
            </div>

            <div class="form-group">
              <label for="emailInput">
                <i class="fa-regular fa-envelope"></i>
                Email Address
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-regular fa-envelope"></i>
                <input
                  type="email"
                  name="email"
                  id="emailInput"
                  placeholder="juan.delacruz@gmail.com"
                  required
                  autocomplete="email"
                  autocapitalize="none"
                  autocorrect="off"
                  spellcheck="false"
                  inputmode="email"
                  enterkeyhint="next"
                  value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES); ?>"
                >
              </div>
              <div class="hint-text" id="emailHint"></div>
            </div>

            <div class="form-group">
              <label for="contact">
                <i class="fa-solid fa-mobile-screen-button"></i>
                Contact Number
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-solid fa-phone"></i>
                <input
                  type="tel"
                  name="contact"
                  id="contact"
                  placeholder="09171234567"
                  required
                  autocomplete="tel"
                  inputmode="tel"
                  enterkeyhint="next"
                  value="<?php echo htmlspecialchars($_POST['contact'] ?? '', ENT_QUOTES); ?>"
                >
              </div>
            </div>

            <div class="form-group">
              <label for="password">
                <i class="fa-solid fa-lock"></i>
                Password
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-solid fa-key"></i>
                <input
                  type="password"
                  name="password"
                  id="password"
                  placeholder="Create a strong password"
                  required
                  autocomplete="new-password"
                  enterkeyhint="next"
                >
                <button type="button" class="toggle-pw" data-target="password" aria-label="Show password">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>

              <div class="strength-meter" id="strengthMeter">
                <div class="strength-bar" id="strengthBar"></div>
              </div>

              <div class="req-box" id="reqBox">
                <div class="req-title">Password Must Contain</div>
                <ul class="req-list">
                  <li id="rule-length"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</li>
                  <li id="rule-upper"><i class="fa-solid fa-circle-xmark"></i> At least one uppercase letter (A-Z)</li>
                  <li id="rule-lower"><i class="fa-solid fa-circle-xmark"></i> At least one lowercase letter (a-z)</li>
                  <li id="rule-number"><i class="fa-solid fa-circle-xmark"></i> At least one number (0-9)</li>
                  <li id="rule-special"><i class="fa-solid fa-circle-xmark"></i> At least one special symbol (!@#$%^&*)</li>
                </ul>
              </div>
            </div>

            <div class="form-group">
              <label for="confirm_password">
                <i class="fa-solid fa-circle-check"></i>
                Confirm Password
              </label>
              <div class="input-wrap">
                <i class="field-icon fa-solid fa-key"></i>
                <input
                  type="password"
                  name="confirm_password"
                  id="confirm_password"
                  placeholder="Re-enter your password"
                  required
                  autocomplete="new-password"
                  enterkeyhint="done"
                >
                <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Show password">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
              <div class="hint-text" id="pwMatchHint"></div>
            </div>

          </div>

          <div class="terms">
            <i class="fa-solid fa-shield-halved"></i>
            <span>
              By creating an account, you confirm that the information you provide
              is accurate and that you agree to use the RENTRACK LGU Owner Portal
              responsibly.
            </span>
          </div>

          <button type="submit" id="submitBtn" class="btn-submit">
            <i class="fa-solid fa-user-plus"></i>
            Create Account
          </button>
        </form>
        <?php endif; ?>

        <div class="footer-link">
          Already have an account?
          <a href="login.php?portal=owner">Sign in here</a>
        </div>

      </div>
    </section>
  </main>

  <!-- MODAL: Confirm Before Submit -->
  <div class="modal-overlay" id="confirmModal">
    <div class="modal-card">
      <div class="modal-icon">
        <i class="fa-solid fa-paper-plane"></i>
      </div>
      <h3>Verify Your Email</h3>
      <p>A verification link will be sent to <span class="target-email" id="displayEmail"></span>. Please check your inbox or spam folder to complete registration.</p>
      <div class="modal-actions">
        <button type="button" class="btn-modal btn-cancel" id="cancelModal">Cancel</button>
        <button type="button" class="btn-modal btn-confirm" id="confirmSubmit">Confirm &amp; Submit</button>
      </div>
    </div>
  </div>

  <!-- MODAL: Registration Success (auto-show kung success) -->
  <?php if ($registration_success): ?>
  <div class="modal-overlay active" id="successModal">
    <div class="modal-card">
      <div class="modal-icon success">
        <i class="fa-solid fa-circle-check"></i>
      </div>
      <h3>Registration Successful!</h3>
      <p>
        Your account <strong style="color:#dc2626;"><?php echo htmlspecialchars($registered_username); ?></strong> has been created.
        <br><br>
        We sent a verification link to <span class="target-email"><?php echo htmlspecialchars($registered_email); ?></span>.
        <br><br>
        <strong style="color:#92400e;">⚠️ You cannot log in yet.</strong> Please verify your email first by clicking the link in your inbox.
      </p>
      <div class="modal-actions">
        <a href="login.php?portal=owner" class="btn-modal btn-confirm">
          <i class="fa-solid fa-right-to-bracket"></i> Go to Login
        </a>
      </div>
      <span class="resend-link">
        Didn't receive the email? <a href="resend_verification.php">Resend verification link</a>
      </span>
    </div>
  </div>
  <?php endif; ?>

  <script>
    (function () {
      'use strict';

      document.querySelectorAll('.toggle-pw').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const input = document.getElementById(this.getAttribute('data-target'));
          if (!input) return;

          const icon = this.querySelector('i');
          const hidden = input.type === 'password';

          input.type = hidden ? 'text' : 'password';

          if (icon) {
            icon.className = hidden
              ? 'fa-regular fa-eye-slash'
              : 'fa-regular fa-eye';
          }

          this.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        });
      });

      const usernameInput = document.getElementById('usernameInput');
      const usernameHint = document.getElementById('usernameHint');
      const usernameRule = document.getElementById('usernameRule');
      let userTimeout = null;

      if (usernameInput && usernameHint) {
        usernameInput.addEventListener('input', function () {
          clearTimeout(userTimeout);

          const val = usernameInput.value.trim();
          const hasNumber = /[0-9]/.test(val);

          if (val === '') {
            usernameHint.textContent = '';
            usernameHint.className = 'hint-text';

            if (usernameRule) {
              usernameRule.className = 'username-rule';
              usernameRule.innerHTML =
                '<i class="fa-solid fa-circle-info"></i> Username must contain at least one number.';
            }
            return;
          }

          if (!hasNumber) {
            usernameHint.textContent = '✗ Add at least one number to your username.';
            usernameHint.className = 'hint-text no-match';

            if (usernameRule) {
              usernameRule.className = 'username-rule invalid';
              usernameRule.innerHTML =
                '<i class="fa-solid fa-circle-xmark"></i> A number (0-9) is required.';
            }
            return;
          }

          if (usernameRule) {
            usernameRule.className = 'username-rule valid';
            usernameRule.innerHTML =
              '<i class="fa-solid fa-circle-check"></i> Number requirement met.';
          }

          userTimeout = setTimeout(function () {
            fetch('check_availability.php?type=username&value=' + encodeURIComponent(val))
              .then(function (res) {
                if (!res.ok) throw new Error('Request failed');
                return res.json();
              })
              .then(function (data) {
                if (data.status === 'taken') {
                  usernameHint.textContent = '✗ This username is already taken.';
                  usernameHint.className = 'hint-text no-match';
                } else if (data.status === 'available') {
                  usernameHint.textContent = '✓ Username is available.';
                  usernameHint.className = 'hint-text match';
                }
              })
              .catch(function () {
                usernameHint.textContent = '';
              });
          }, 350);
        });
      }

      const emailInput = document.getElementById('emailInput');
      const emailHint = document.getElementById('emailHint');
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      let emailTimeout = null;

      if (emailInput && emailHint) {
        emailInput.addEventListener('input', function () {
          clearTimeout(emailTimeout);

          const val = emailInput.value.trim();

          if (val === '') {
            emailHint.textContent = '';
            emailHint.className = 'hint-text';
            return;
          }

          if (!emailPattern.test(val)) {
            emailHint.textContent = '✗ Please enter a valid email address.';
            emailHint.className = 'hint-text no-match';
            return;
          }

          emailTimeout = setTimeout(function () {
            fetch('check_availability.php?type=email&value=' + encodeURIComponent(val))
              .then(function (res) {
                if (!res.ok) throw new Error('Request failed');
                return res.json();
              })
              .then(function (data) {
                if (data.status === 'taken') {
                  emailHint.textContent = '✗ This email is already registered.';
                  emailHint.className = 'hint-text no-match';
                } else if (data.status === 'available') {
                  emailHint.textContent = '✓ Email is valid and available.';
                  emailHint.className = 'hint-text match';
                }
              })
              .catch(function () {
                emailHint.textContent = '';
              });
          }, 350);
        });
      }

      const pwInput = document.getElementById('password');
      const confirmInput = document.getElementById('confirm_password');
      const matchHint = document.getElementById('pwMatchHint');
      const reqBox = document.getElementById('reqBox');
      const strengthBar = document.getElementById('strengthBar');

      const rules = {
        length: { test: /.{8,}/, el: document.getElementById('rule-length') },
        upper: { test: /[A-Z]/, el: document.getElementById('rule-upper') },
        lower: { test: /[a-z]/, el: document.getElementById('rule-lower') },
        number: { test: /[0-9]/, el: document.getElementById('rule-number') },
        special: { test: /[^A-Za-z0-9]/, el: document.getElementById('rule-special') }
      };

      function updateChecklist() {
        if (!pwInput) return;
        const value = pwInput.value;
        let metCount = 0;

        Object.keys(rules).forEach(function (key) {
          const rule = rules[key];
          if (!rule.el) return;

          const met = rule.test.test(value);
          rule.el.classList.toggle('met', met);

          const icon = rule.el.querySelector('i');
          if (icon) {
            icon.className = met ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
          }

          if (met) metCount++;
        });

        const percentage = (metCount / 5) * 100;
        if (strengthBar) {
          strengthBar.style.width = percentage + '%';
          if (metCount <= 2) {
            strengthBar.style.background = '#dc2626';
          } else if (metCount <= 4) {
            strengthBar.style.background = '#d97706';
          } else {
            strengthBar.style.background = '#059669';
          }
        }
      }

      function checkMatch() {
        if (!pwInput || !confirmInput || !matchHint) return;

        const confirmValue = confirmInput.value;

        if (confirmValue === '') {
          matchHint.textContent = '';
          matchHint.className = 'hint-text';
        } else if (confirmValue === pwInput.value) {
          matchHint.textContent = '✓ Passwords match.';
          matchHint.className = 'hint-text match';
        } else {
          matchHint.textContent = '✗ Passwords do not match.';
          matchHint.className = 'hint-text no-match';
        }
      }

      if (pwInput) {
        pwInput.addEventListener('focus', function () {
          if (reqBox) reqBox.style.display = 'block';
        });

        pwInput.addEventListener('input', function () {
          if (reqBox) reqBox.style.display = 'block';
          updateChecklist();
          checkMatch();
          updateProgress();
        });
      }

      if (confirmInput) {
        confirmInput.addEventListener('input', function () {
          checkMatch();
          updateProgress();
        });
      }

      const tracked = [
        'fullname',
        'username',
        'email',
        'contact',
        'password',
        'confirm_password'
      ];

      const progressLabel = document.getElementById('progressLabel');

      function updateProgress() {
        let completed = 0;

        tracked.forEach(function (name) {
          const field = document.querySelector('[name="' + name + '"]');
          const segment = document.querySelector(
            '.progress-seg[data-field="' + name + '"]'
          );

          const filled = field && field.value.trim() !== '';

          if (segment) {
            segment.classList.toggle('filled', filled);
          }

          if (filled) completed++;
        });

        if (progressLabel) {
          progressLabel.textContent =
            completed + ' of ' + tracked.length + ' completed';
        }
      }

      tracked.forEach(function (name) {
        const field = document.querySelector('[name="' + name + '"]');

        if (field) {
          field.addEventListener('input', updateProgress);
        }
      });

      updateChecklist();
      checkMatch();
      updateProgress();

      const form = document.getElementById('registerForm');
      const submitBtn = document.getElementById('submitBtn');
      const modal = document.getElementById('confirmModal');
      const cancelModal = document.getElementById('cancelModal');
      const confirmSubmit = document.getElementById('confirmSubmit');
      const displayEmail = document.getElementById('displayEmail');

      if (form && submitBtn && modal) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();

          const username = usernameInput ? usernameInput.value.trim() : '';

          if (!/[0-9]/.test(username)) {
            if (usernameHint) {
              usernameHint.textContent = '✗ Username must contain at least one number (0-9).';
              usernameHint.className = 'hint-text no-match';
            }
            if (usernameRule) {
              usernameRule.className = 'username-rule invalid';
              usernameRule.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> A number (0-9) is required.';
            }
            if (usernameInput) usernameInput.focus();
            return;
          }

          if (displayEmail && emailInput) {
            displayEmail.textContent = emailInput.value.trim();
          }
          modal.classList.add('active');
        });

        if (cancelModal) {
          cancelModal.addEventListener('click', function () {
            modal.classList.remove('active');
          });
        }

        modal.addEventListener('click', function (e) {
          if (e.target === modal) {
            modal.classList.remove('active');
          }
        });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && modal.classList.contains('active')) {
            modal.classList.remove('active');
          }
        });

        if (confirmSubmit) {
          confirmSubmit.addEventListener('click', function () {
            modal.classList.remove('active');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating Account...';
            form.submit();
          });
        }
      }
    })();
  </script>
</body>
</html>