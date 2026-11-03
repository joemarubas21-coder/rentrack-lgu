<?php
// ============================================
// ✅ REACT API SUPPORT
// ============================================
$is_api = isset($_SERVER["CONTENT_TYPE"]) && strpos($_SERVER["CONTENT_TYPE"], "application/json") !== false;
if ($is_api) {
    header('Content-Type: application/json');
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if ($data) {
        if (isset($data['username'])) {
            $_POST['login'] = 1;
            $_POST['username'] = $data['username'];
            $_POST['password'] = $data['password'];
            $_POST['portal'] = $data['portal'] ?? '';
            $_POST['g-recaptcha-response'] = $data['recaptcha'] ?? '';
        } elseif (isset($data['answer'])) {
            $_POST['verify_math'] = 1;
            $_POST['portal'] = $data['portal'] ?? '';
            $_POST['math_answer'] = $data['answer'];
        }
    }
}
// ============================================

require_once __DIR__ . '/config/session.php'; app_session_start();
require_once 'config/db.php';

// ============================================
// ✅ reCAPTCHA CONFIGURATION
// ============================================
define('RECAPTCHA_SITE_KEY', env_value('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', env_value('RECAPTCHA_SECRET_KEY', ''));

// I-reset ang pending sessions kapag bagong visit / fresh GET request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['clear'])) {
    unset(
        $_SESSION['math_pending'], 
        $_SESSION['captcha_num1'], 
        $_SESSION['captcha_num2'], 
        $_SESSION['captcha_ans'], 
        $_SESSION['temp_user'],
        $_SESSION['math_attempts']
    );
}

$error = "";

// ======================================
// LOGIN ATTEMPTS & LOCKOUT SYSTEM
// ======================================
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

$is_locked_out = false;
$remaining_cooldown = 0;

if (isset($_SESSION['lockout_time'])) {
    $time_passed = time() - $_SESSION['lockout_time'];
    if ($time_passed < 30) {
        $is_locked_out = true;
        $remaining_cooldown = 30 - $time_passed;
        $error = "Too many failed login attempts. Please wait " . $remaining_cooldown . " seconds before trying again.";
    } else {
        unset($_SESSION['lockout_time']);
        $_SESSION['login_attempts'] = 0;
    }
}

// ======================================
// PORTAL SELECTION
// ======================================
$allowed_portals = ['admin', 'owner', 'inspector', 'staff'];
$portal = $_POST['portal'] ?? ($_GET['portal'] ?? '');

if (!in_array($portal, $allowed_portals, true) && !$is_api) {
    header("Location: choose_portal.php");
    exit();
}

/* =====================================================
   ✅ OPTIMIZED HELPER: VERIFY reCAPTCHA (cURL)
===================================================== */
function verifyRecaptcha($recaptchaResponse) {
    if (empty($recaptchaResponse)) {
        return false;
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds max
    
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        return false;
    }

    $responseData = json_decode($result, true);
    return isset($responseData['success']) && $responseData['success'] === true;
}

/* =====================================================
   STEP 2: MATH VERIFICATION (ALL PORTALS)
===================================================== */
if (!isset($_SESSION['math_attempts'])) {
    $_SESSION['math_attempts'] = 0;
}

if (isset($_POST['verify_math'])) {
    $user_ans = trim($_POST['math_answer'] ?? '');

    if (empty($_SESSION['math_pending']) || empty($_SESSION['temp_user'])) {
        $error = "Session expired. Please log in again.";
    } elseif ($user_ans === '' || (int)$user_ans !== ($_SESSION['captcha_ans'] ?? -999)) {
        
        $_SESSION['math_attempts'] += 1;
        $max_math_attempts = 3;
        $math_left = $max_math_attempts - $_SESSION['math_attempts'];

        if ($_SESSION['math_attempts'] >= $max_math_attempts) {
            unset($_SESSION['math_pending'], $_SESSION['captcha_num1'], $_SESSION['captcha_num2'], $_SESSION['captcha_ans'], $_SESSION['temp_user'], $_SESSION['math_attempts']);
            if (!$is_api) {
                header("Location: choose_portal.php?error=math_failed");
                exit();
            }
            $error = "Too many failed attempts.";
        } else {
            $error = "Incorrect verification answer. You have " . $math_left . " attempt(s) left.";
            $_SESSION['captcha_num1'] = random_int(1, 9);
            $_SESSION['captcha_num2'] = random_int(1, 9);
            $_SESSION['captcha_ans']  = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
        }

    } else {
        $user = $_SESSION['temp_user'];
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        unset($_SESSION['math_pending'], $_SESSION['captcha_num1'], $_SESSION['captcha_num2'], $_SESSION['captcha_ans'], $_SESSION['temp_user'], $_SESSION['login_attempts'], $_SESSION['lockout_time'], $_SESSION['math_attempts']);

        if (!$is_api) {
            switch ($user['role']) {
                case 'owner': header("Location: owner/dashboard.php"); exit();
                case 'admin': header("Location: admin/dashboard.php"); exit();
                case 'inspector': header("Location: inspector/dashboard.php"); exit();
                case 'staff': header("Location: staff/dashboard.php"); exit();
                default:
                    session_unset();
                    session_destroy();
                    $error = "Invalid account role. Please contact the administrator.";
                    break;
            }
        }
    }
}

/* =====================================================
   STEP 1: LOGIN PROCESS (WITH reCAPTCHA)
===================================================== */
if (isset($_POST['login']) && !$is_locked_out) {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    if (!verifyRecaptcha($recaptcha_response)) {
        $error = "Please complete the reCAPTCHA to verify you are not a robot.";
    } elseif ($username === "" || $password === "") {
        $error = "Please enter your username and password.";
    } else {

        try {
            $stmt = $conn->prepare(
                "SELECT id, fullname, username, password, email, contact, role, status, is_verified
                 FROM users
                 WHERE username = :username
                 LIMIT 1"
            );

            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                // ✅ INALIS ANG EMAIL VERIFICATION CHECK DITO
                if ($user['status'] !== 'Active') {
                    $error = "This account is inactive. Please contact the administrator.";
                } 
                elseif ($user['role'] !== $portal) {
                    $error = "This account is not authorized to log in through this portal.";
                } 
                else {
                    $_SESSION['login_attempts'] = 0;
                    unset($_SESSION['lockout_time']);

                    // ✅ LAHAT NG PORTAL AY DADAAN NA SA MATH VERIFICATION
                    $_SESSION['math_pending'] = true;
                    $_SESSION['math_attempts'] = 0;
                    $_SESSION['captcha_num1'] = random_int(1, 9);
                    $_SESSION['captcha_num2'] = random_int(1, 9);
                    $_SESSION['captcha_ans']  = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
                    $_SESSION['temp_user']    = $user;
                }

            } else {
                $_SESSION['login_attempts'] += 1;
                $max_attempts = 3;
                $attempts_left = $max_attempts - $_SESSION['login_attempts'];

                if ($_SESSION['login_attempts'] >= $max_attempts) {
                    $_SESSION['lockout_time'] = time();
                    $is_locked_out = true;
                    $remaining_cooldown = 30;
                    $error = "Too many failed login attempts. Account locked for 30 seconds.";
                } else {
                    $error = "Invalid credentials. You have " . $attempts_left . " left attempt(s).";
                }
            }

        } catch (PDOException $e) {
            $error = "Database error. Please try again.";
        }
    }
}

// Portal display data
$portalNames = [
    'admin' => 'Administrator',
    'owner' => 'Property Owner',
    'inspector' => 'Inspector',
    'staff' => 'LGU Staff'
];
$portalDisplay = $portalNames[$portal] ?? ucfirst($portal);

$portalIcons = [
    'admin' => 'fa-user-shield',
    'owner' => 'fa-house-chimney-user',
    'inspector' => 'fa-magnifying-glass',
    'staff' => 'fa-users-gear'
];
$portalIcon = $portalIcons[$portal] ?? 'fa-user';

// ✅ Determine kung nasa Math Verification step ba tayo
$is_math_step = (!empty($_SESSION['math_pending']));

// ============================================
// ✅ REACT API RESPONSES
// ============================================
if ($is_api) {
    if (isset($_POST['login'])) {
        if ($is_locked_out) {
            echo json_encode(['status' => 'locked', 'lockSeconds' => $remaining_cooldown, 'message' => $error]);
        } elseif ($error !== "") {
            echo json_encode(['status' => 'error', 'message' => $error]);
        } elseif ($is_math_step) {
            echo json_encode(['status' => 'verify_math', 'math' => ['num1' => $_SESSION['captcha_num1'], 'num2' => $_SESSION['captcha_num2']]]);
        }
        exit;
    }
    
    if (isset($_POST['verify_math'])) {
        if ($error !== "") {
            if (strpos($error, 'Too many') !== false) {
                echo json_encode(['status' => 'failed', 'message' => $error]);
            } else {
                echo json_encode(['status' => 'error', 'message' => $error, 'math' => ['num1' => $_SESSION['captcha_num1'] ?? 0, 'num2' => $_SESSION['captcha_num2'] ?? 0]]);
            }
        } else {
            echo json_encode(['status' => 'ok', 'redirect' => '/dashboard']);
        }
        exit;
    }
}
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#dc2626">
    <title>Login | RENTRACK LGU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- reCAPTCHA SCRIPT -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

    <style>
        /* ============================================
           RESET & BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-card-alt: #f1f5f9;
            --red-accent: #dc2626;
            --red-hover: #ef4444;
            --red-soft: #fee2e2;
            --red-deep: #991b1b;
            --gold-accent: #d97706;
            --teal-accent: #0891b2;
            --text-main: #0f172a;
            --text-sub: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --border-strong: #cbd5e1;
            --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            background-color: var(--bg-body);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 15% 20%, rgba(220, 38, 38, 0.04) 0%, transparent 55%),
                radial-gradient(ellipse at 85% 80%, rgba(8, 145, 178, 0.04) 0%, transparent 55%),
                linear-gradient(160deg, #f8fafc 0%, #f1f5f9 60%, #eef2f7 100%);
            z-index: -1;
            pointer-events: none;
        }

        /* ============================================
           BOOK-STYLE SPLIT LOGIN CARD
        ============================================ */
        .login-book {
            display: flex;
            width: 100%;
            max-width: 880px;
            min-height: 520px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 20px 45px -12px rgba(15, 23, 42, 0.10),
                0 4px 12px -4px rgba(15, 23, 42, 0.05);
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .login-left {
            flex: 1;
            background: linear-gradient(145deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            color: #fff;
        }

        .login-left::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15), transparent 70%);
            top: -100px;
            left: -100px;
            pointer-events: none;
        }

        .login-left::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1), transparent 70%);
            bottom: -80px;
            right: -80px;
            pointer-events: none;
        }

        .login-left .logo-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 38px;
            color: var(--red-accent);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 1;
        }

        .login-left h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.3px;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .login-left .subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 500;
            line-height: 1.5;
            max-width: 260px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .login-left .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            padding: 8px 20px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            position: relative;
            z-index: 1;
        }

        .login-right {
            flex: 1;
            background: var(--bg-card);
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        /* ALERTS */
        .error-message {
            background: var(--red-soft);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: var(--red-accent);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12.5px;
            position: relative;
            z-index: 1;
            font-weight: 500;
        }

        /* FORM ELEMENTS */
        .login-form h2 { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 4px; }
        .login-form .form-subtitle { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; font-weight: 500; }
        .form-group { margin-bottom: 16px; position: relative; }
        
        .form-group .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            background: var(--bg-card-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 13.5px;
            outline: none;
            font-weight: 500;
        }

        .form-group input:disabled { opacity: 0.6; cursor: not-allowed; background: #e2e8f0; }
        .form-group input:focus { border-color: var(--red-accent); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 15px;
        }

        /* reCAPTCHA */
        .recaptcha-wrapper { display: flex; justify-content: center; margin-bottom: 16px; filter: hue-rotate(140deg) saturate(3) brightness(0.95); }
        .recaptcha-wrapper .g-recaptcha { transform: scale(0.92); transform-origin: center; }

        /* OPTIONS & BUTTON */
        .options-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .options-row .remember { display: flex; align-items: center; gap: 7px; color: var(--text-sub); font-size: 12.5px; font-weight: 500; cursor: pointer; }
        .options-row .remember input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--red-accent); }
        .options-row .forgot-link { color: var(--text-muted); font-size: 12.5px; font-weight: 600; text-decoration: none; }

        .login-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: var(--red-accent);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-btn:hover:not(:disabled) { background: var(--red-hover); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(220, 38, 38, 0.3); }
        .login-btn:disabled { opacity: 0.6; cursor: not-allowed; }

        /* FOOTER */
        .login-footer { text-align: center; margin-top: 24px; }
        .login-footer .divider { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; color: var(--text-muted); font-size: 11px; }
        .login-footer .divider::before, .login-footer .divider::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }
        .login-footer a { color: var(--text-sub); text-decoration: none; font-size: 12.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
        
        .modern-action-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-size: 13px;
            font-weight: 600;
        }

        .modern-admin-notice {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
        }

        .back-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-sub);
            margin-top: 18px;
        }

        /* MATH VERIFICATION */
        .verify-standalone { width: 100%; max-width: 480px; padding: 20px; }
        .verify-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 24px; padding: 48px 36px; box-shadow: 0 20px 45px -12px rgba(15, 23, 42, 0.10); text-align: center; }
        .verify-card .math-eq { font-size: 54px; font-weight: 800; color: var(--red-accent); letter-spacing: 8px; margin-bottom: 32px; font-family: 'Space Grotesk', sans-serif; }
        
        .verify-card .math-input {
            width: 100%;
            padding: 20px 18px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            border-radius: 16px;
            background: var(--bg-card-alt);
            border: 2px solid var(--border-color);
            color: var(--text-main);
            outline: none;
            -moz-appearance: textfield;
        }

        .verify-card .math-input::-webkit-outer-spin-button, .verify-card .math-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .verify-card .math-input:focus { border-color: var(--red-accent); box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1); background: #ffffff; }
        
        .verify-card .status-emoji { font-size: 52px; margin-top: 20px; display: none; }
        .verify-card .status-emoji.sad { color: #ef4444; }
        .verify-card .status-emoji.happy { color: #10b981; }

        .verify-error { display: none; align-items: center; justify-content: center; gap: 6px; font-size: 12.5px; font-weight: 600; margin-top: 14px; padding: 10px 14px; border-radius: 8px; }
        .verify-error.show { display: flex; background: var(--red-soft); border: 1px solid rgba(220, 38, 38, 0.2); color: var(--red-accent); }

        @media (max-width: 720px) {
            .login-book { flex-direction: column; max-width: 440px; min-height: auto; }
            .login-left { padding: 36px 20px; }
            .login-right { padding: 32px 24px; }
            .login-left .logo-icon { width: 70px; height: 70px; font-size: 30px; margin-bottom: 14px; }
            .login-left h1 { font-size: 24px; }
        }

        @media (max-width: 520px) {
            .verify-card .math-eq { font-size: 40px; letter-spacing: 5px; }
            .verify-card .math-input { font-size: 22px; padding: 18px 14px; }
            .verify-card { padding: 36px 24px; }
        }

        /* MODALS */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(6px); z-index: 999; display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .custom-modal { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; width: 100%; max-width: 460px; padding: 24px; transform: scale(0.9); transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
        .modal-overlay.active .custom-modal { transform: scale(1); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color); }
        .modal-title { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .modal-title i { color: var(--red-accent); }
        .modal-close { background: var(--bg-card-alt); border: 1px solid var(--border-color); color: var(--text-muted); width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .modal-body { font-size: 13px; line-height: 1.6; color: var(--text-sub); }
        .info-reminder-box { background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 12px; display: flex; gap: 10px; }
        .info-reminder-box i { color: var(--red-accent); font-size: 16px; }
        .modal-footer { margin-top: 20px; display: flex; justify-content: space-between; align-items: center; }
        .btn-modal-ok { background: var(--red-accent); border: none; padding: 10px 20px; border-radius: 8px; color: #ffffff; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    </style>
</head>
<body>

<?php if ($is_math_step): ?>
    <!-- =====================================================
         ✅ MATH VERIFICATION — PARA SA LAHAT NG PORTAL
    ===================================================== -->
    <div class="verify-standalone">
        <div class="verify-card">
            <form method="POST" id="mathForm" autocomplete="off">
                <input type="hidden" name="portal" value="<?php echo htmlspecialchars($portal); ?>">
                <input type="hidden" name="verify_math" value="1">

                <div class="math-eq">
                    <?php echo $_SESSION['captcha_num1'] . ' + ' . $_SESSION['captcha_num2'] . ' = ?'; ?>
                </div>

                <input
                    type="number"
                    name="math_answer"
                    id="mathAnswerInput"
                    class="math-input"
                    required
                    autofocus
                    autocomplete="off"
                    placeholder="?"
                >

                <div class="status-emoji happy" id="happyEmoji">
                    <i class="fa-solid fa-face-smile"></i>
                </div>
                <div class="status-emoji sad" id="sadEmoji">
                    <i class="fa-solid fa-face-frown"></i>
                </div>

                <div class="verify-error <?php echo ($error !== "" && $is_math_step) ? 'show' : ''; ?>">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- =====================================================
         BOOK-STYLE LAYOUT (Login Form)
    ===================================================== -->
    <div class="login-book">
        <div class="login-left">
            <div class="logo-icon">
                <i class="fa-solid <?php echo $portalIcon; ?>"></i>
            </div>
            <h1><span style="color:#ffffff;">RENTRACK</span> LGU</h1>
            <p class="subtitle">Unified Rental Registration, Tracking and Compliance System</p>
            <div class="portal-badge">
                <i class="fa-solid <?php echo $portalIcon; ?>"></i>
                <?php echo htmlspecialchars($portalDisplay); ?> Portal
            </div>
        </div>

        <div class="login-right">
            <!-- Error Message -->
            <?php if ($error !== "" && !$is_math_step) { ?>
                <div class="error-message" id="errorMessage">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php } ?>

            <!-- LOGIN FORM -->
            <form method="POST" class="login-form" id="mainLoginForm" autocomplete="off">
                <input type="hidden" name="portal" value="<?php echo htmlspecialchars($portal); ?>">

                <h2>Sign In</h2>
                <p class="form-subtitle">Enter your credentials to continue</p>

                <div class="form-group">
                    <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" autocomplete="username" required <?php echo $is_locked_out ? 'disabled' : ''; ?>>
                    <span class="input-icon"><i class="fa-regular fa-user"></i></span>
                </div>

                <div class="form-group">
                    <input type="password" name="password" placeholder="Password" id="password" autocomplete="current-password" required <?php echo $is_locked_out ? 'disabled' : ''; ?>>
                    <span class="input-icon"><i class="fa-solid fa-key"></i></span>
                    <button type="button" class="password-toggle" id="togglePassword"><i class="fa-regular fa-eye" id="toggleIcon"></i></button>
                </div>

                <div class="options-row" style="<?php echo ($portal === 'admin') ? 'justify-content: flex-end;' : ''; ?>">
                    <?php if ($portal !== 'admin'): ?>
                        <label class="remember"><input type="checkbox" name="remember" <?php echo $is_locked_out ? 'disabled' : ''; ?>> Remember me</label>
                    <?php endif; ?>
                    
                    <?php if (in_array($portal, ['staff', 'inspector'])): ?>
                        <a href="javascript:void(0);" id="openForgotModalBtn" class="forgot-link">Forgot password?</a>
                    <?php elseif ($portal === 'owner'): ?>
                        <a href="forgot_password.php?portal=owner" class="forgot-link">Forgot password?</a>
                    <?php endif; ?>
                </div>

                <div class="recaptcha-wrapper">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>" data-theme="light" data-size="normal"></div>
                </div>

                <button type="submit" name="login" id="loginSubmitBtn" class="login-btn" <?php echo $is_locked_out ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-right-to-bracket" id="loginIcon"></i>
                    <span id="btnText"><?php echo $is_locked_out ? "Locked (" . $remaining_cooldown . "s)" : "Sign In"; ?></span>
                </button>
            </form>

            <!-- Footer -->
            <div class="login-footer">
                <?php if ($portal === 'owner') { ?>
                    <div class="divider">or</div>
                    <div class="links">
                        <a href="register.php" class="modern-action-link"><i class="fa-solid fa-user-plus"></i> New Owner? Register Here</a>
                    </div>
                <?php } elseif ($portal === 'admin') { ?>
                    <div class="divider" style="margin-bottom:8px;"></div>
                    <div class="links"><div class="modern-admin-notice"><i class="fa-solid fa-lock"></i> <span>Secure Administrator Access Only</span></div></div>
                <?php } else { ?>
                    <div class="divider" style="margin-bottom:8px;"></div>
                    <div class="links">
                        <a href="#" id="contactAdminBtn" style="font-size:12px; cursor:pointer; font-weight:600;">
                            <i class="fa-solid fa-headset"></i> Need an account? Contact System Administrator
                        </a>
                    </div>
                <?php } ?>
                <div>
                    <a href="choose_portal.php" class="back-icon-btn" title="Back to portal selection"><i class="fa-solid fa-arrow-left"></i></a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

    <!-- MODALS -->
    <div class="modal-overlay" id="forgotPasswordNoticeModal">
        <div class="custom-modal" style="max-width: 400px; text-align: center;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--red-soft); border: 2px solid rgba(220, 38, 38, 0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; color: var(--red-accent); font-size: 24px;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h3 style="font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; margin-bottom: 8px;">Password Reset Restricted</h3>
            <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px; font-weight: 500;">Please contact the <strong style="color: var(--text-main);">System Administrator</strong> to reset your portal password.</p>
            <button type="button" class="btn-modal-ok" id="closeForgotModalBtn" style="width: 100%; justify-content: center; padding: 12px; font-size: 13px;"><i class="fa-solid fa-check"></i> Understood</button>
        </div>
    </div>

    <div class="modal-overlay" id="contactModal">
        <div class="custom-modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-user-plus"></i> Request Account Access</div>
                <button type="button" class="modal-close" id="closeModalBtn"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="accountRequestForm">
                <div class="modal-body">
                    <div class="info-reminder-box"><i class="fa-solid fa-circle-info"></i> <span>If you have already submitted a request, please check your registered email inbox for your login credentials.</span></div>
                    <p style="font-size: 12.5px; margin-bottom: 14px; font-weight: 500;">Fill in your official LGU details below to request an account.</p>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 600; display: block; margin-bottom: 4px;">Full Name</label>
                            <input type="text" name="fullname" required placeholder="e.g. Juan Dela Cruz" style="width: 100%; padding: 10px 12px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 600; display: block; margin-bottom: 4px;">Email Address</label>
                            <input type="email" name="email" required placeholder="e.g. juan.delacruz@gmail.com" style="width: 100%; padding: 10px 12px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <div style="flex: 1;">
                                <label style="font-size: 11px; font-weight: 600; display: block; margin-bottom: 4px;">Employee ID</label>
                                <input type="text" name="employee_id" required placeholder="EMP-2026-XXXX" style="width: 100%; padding: 10px 12px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; outline: none;">
                            </div>
                            <div style="flex: 1;">
                                <label style="font-size: 11px; font-weight: 600; display: block; margin-bottom: 4px;">Requested Role</label>
                                <select name="requested_role" required style="width: 100%; padding: 10px 12px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; outline: none;">
                                    <option value="staff" <?php echo ($portal === 'staff') ? 'selected' : ''; ?>>LGU Staff</option>
                                    <option value="inspector" <?php echo ($portal === 'inspector') ? 'selected' : ''; ?>>Inspector</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <span id="reqFormMsg" style="font-size: 12px; font-weight: 600;"></span>
                    <button type="submit" class="btn-modal-ok" id="submitReqBtn"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        (function() {
            // Password Toggle
            const toggleBtn = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const isPassword = passwordInput.type === 'password';
                    passwordInput.type = isPassword ? 'text' : 'password';
                    document.getElementById('toggleIcon').className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                });
            }

            // Auto-focus
            const usernameInput = document.querySelector('input[name="username"]');
            if (usernameInput && !usernameInput.value && !usernameInput.disabled) usernameInput.focus();

            // LOCKOUT COUNTDOWN
            const isLocked = <?php echo $is_locked_out ? 'true' : 'false'; ?>;
            let remainingTime = <?php echo (int)$remaining_cooldown; ?>;

            if (isLocked && remainingTime > 0) {
                const loginBtn = document.getElementById('loginSubmitBtn');
                const btnText = document.getElementById('btnText');
                const inputs = document.querySelectorAll('#mainLoginForm input');
                const errorText = document.getElementById('errorText');

                const countdownInterval = setInterval(() => {
                    remainingTime--;
                    if (btnText) btnText.innerText = `Locked (${remainingTime}s)`;
                    if (errorText) errorText.innerText = `Too many failed login attempts. Please wait ${remainingTime} seconds.`;

                    if (remainingTime <= 0) {
                        clearInterval(countdownInterval);
                        inputs.forEach(input => input.disabled = false);
                        if (loginBtn) loginBtn.disabled = false;
                        if (btnText) btnText.innerText = 'Sign In';
                        const errorMsg = document.querySelector('.error-message');
                        if (errorMsg) errorMsg.style.display = 'none';
                    }
                }, 1000);
            }

            // ✅ MATH VERIFICATION
            const mathAnswerInput = document.getElementById('mathAnswerInput');
            const mathForm = document.getElementById('mathForm');
            const happyEmoji = document.getElementById('happyEmoji');
            const sadEmoji = document.getElementById('sadEmoji');
            const expectedAnswer = <?php echo (int)($_SESSION['captcha_ans'] ?? -999); ?>;

            if (mathAnswerInput && mathForm) {
                mathAnswerInput.addEventListener('input', function() {
                    const val = parseInt(this.value.trim());

                    if (!isNaN(val)) {
                        if (val === expectedAnswer) {
                            if (sadEmoji) sadEmoji.style.display = 'none';
                            if (happyEmoji) happyEmoji.style.display = 'block';
                            setTimeout(() => { mathForm.submit(); }, 500);
                        } else if (this.value.length >= String(expectedAnswer).length) {
                            if (happyEmoji) happyEmoji.style.display = 'none';
                            if (sadEmoji) sadEmoji.style.display = 'block';
                            setTimeout(() => { mathForm.submit(); }, 800);
                        }
                    } else {
                        if (happyEmoji) happyEmoji.style.display = 'none';
                        if (sadEmoji) sadEmoji.style.display = 'none';
                    }
                });
            }

            // reCAPTCHA FRONTEND VALIDATION
            const mainLoginForm = document.getElementById('mainLoginForm');
            if (mainLoginForm && typeof grecaptcha !== 'undefined') {
                mainLoginForm.addEventListener('submit', function(e) {
                    const recaptchaResponse = grecaptcha.getResponse();
                    if (!recaptchaResponse || recaptchaResponse.length === 0) {
                        e.preventDefault();
                        let errBox = document.getElementById('errorMessage');
                        if (!errBox) {
                            errBox = document.createElement('div');
                            errBox.id = 'errorMessage';
                            errBox.className = 'error-message';
                            errBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i><span id="errorText"></span>';
                            mainLoginForm.insertAdjacentElement('beforebegin', errBox);
                        }
                        const errText = document.getElementById('errorText');
                        if (errText) errText.innerText = 'Please check the "I\'m not a robot" box first.';
                        errBox.style.display = 'flex';
                        return false;
                    }
                });
            }

            // MODALS
            const forgotModal = document.getElementById('forgotPasswordNoticeModal');
            const openForgotBtn = document.getElementById('openForgotModalBtn');
            const closeForgotBtn = document.getElementById('closeForgotModalBtn');
            if (openForgotBtn && forgotModal) openForgotBtn.addEventListener('click', (e) => { e.preventDefault(); forgotModal.classList.add('active'); });
            if (closeForgotBtn && forgotModal) closeForgotBtn.addEventListener('click', () => forgotModal.classList.remove('active'));

            const modal = document.getElementById('contactModal');
            const openBtn = document.getElementById('contactAdminBtn');
            const closeBtn = document.getElementById('closeModalBtn');
            if (openBtn) openBtn.addEventListener('click', (e) => { e.preventDefault(); modal.classList.add('active'); });
            if (closeBtn) closeBtn.addEventListener('click', () => modal.classList.remove('active'));
            if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('active'); });

            // AJAX FORM
            const reqForm = document.getElementById('accountRequestForm');
            if (reqForm) {
                reqForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const btn = document.getElementById('submitReqBtn');
                    const msg = document.getElementById('reqFormMsg');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
                    msg.innerText = '';

                    fetch('request_account.php', { method: 'POST', body: new FormData(this) })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            msg.style.color = '#059669';
                            msg.innerText = 'Request submitted successfully!';
                            reqForm.reset();
                            setTimeout(() => { modal.classList.remove('active'); msg.innerText = ''; }, 2000);
                        } else {
                            msg.style.color = '#dc2626';
                            msg.innerText = data.message || 'An error occurred. Please try again.';
                        }
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
                    })
                    .catch(err => {
                        msg.style.color = '#dc2626';
                        msg.innerText = 'Network error. Please try again.';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
                    });
                });
            }
        })();
    </script>
</body>
</html>