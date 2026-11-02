<?php
// resend_verification.php
require_once __DIR__ . '/config/session.php'; app_session_start();

require_once 'config/db.php';
require_once 'config/logger.php';

$message = '';
$message_type = ''; // 'success' o 'error'
$prefilled_email = '';

// Kung may pumasok na email via GET (galing sa login page warning card)
if (isset($_GET['email']) && filter_var($_GET['email'], FILTER_VALIDATE_EMAIL)) {
    $prefilled_email = htmlspecialchars($_GET['email']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $email = trim($_POST['email'] ?? '');
    $prefilled_email = htmlspecialchars($email);

    if (empty($email)) {
        $message = 'Please enter your email address.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    } else {
        try {
            // Hanapin ang user sa database
            $stmt = $conn->prepare(
                "SELECT id, fullname, username, email, is_verified, verification_token 
                 FROM users 
                 WHERE LOWER(email) = LOWER(:email) 
                 LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = 'No account found with that email address. Please check your spelling or register a new account.';
                $message_type = 'error';
            } 
            elseif ((int)$user['is_verified'] === 1) {
                // Verified na ang account
                $message = 'Your account is already verified. You can now <a href="login.php?portal=owner" style="color:#f4c84a;font-weight:700;">sign in here</a>.';
                $message_type = 'success';
            } 
            else {
                // ✅ UNVERIFIED PA — Gumawa ng bagong token
                $new_token = bin2hex(random_bytes(32));

                $update = $conn->prepare(
                    "UPDATE users 
                     SET verification_token = :token 
                     WHERE id = :id"
                );
                $update->execute([
                    ':token' => $new_token,
                    ':id'    => $user['id']
                ]);

                // I-log ang resend action
                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        (int)$user['id'],
                        "Resent verification email to: {$email}",
                        'owner',
                        $_SERVER['REMOTE_ADDR'] ?? null
                    );
                }

                // Build verification link
                $verify_link = site_base_url() . "/verify.php?token=" . $new_token;

                // ✅ I-EMAIL GAMIT ANG RESEND API (HTTPS)
                try {
                    $api_key = env_value('RESEND_API_KEY', '');
                    $safeName = htmlspecialchars($user['fullname']);

                    $email_body = "
                    <html>
                    <body style='font-family: Arial, sans-serif; background-color: #0a0f1e; color: #ffffff; padding: 20px;'>
                        <div style='max-width: 600px; margin: 0 auto; background: #142b50; padding: 30px; border-radius: 12px;'>
                            <h2 style='color: #facc15;'>Greetings, {$safeName}!</h2>
                            <p>You requested a new verification link for your <strong>RENTRACK LGU Owner Portal</strong> account.</p>
                            <p>Please click the button below to activate your account:</p>
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='{$verify_link}' style='background-color: #facc15; color: #0a1628; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Verify Email Address</a>
                            </div>
                            <p style='font-size: 12px; color: #94a3b8;'>If the button doesn't work, copy and paste this link into your browser:</p>
                            <p style='font-size: 11px; color: #60a5fa; word-break: break-all;'>{$verify_link}</p>
                            <p style='font-size: 12px; color: #aaaaaa; margin-top: 20px;'>If you did not request this, please ignore this email.</p>
                        </div>
                    </body>
                    </html>";

                    $email_data = [
                        "from" => "RENTRACK LGU <onboarding@resend.dev>",
                        "to" => [$email],
                        "subject" => "Resend: Confirm your RENTRACK LGU Account",
                        "html" => $email_body
                    ];

                    $ch = curl_init('https://api.resend.com/emails');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email_data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $api_key,
                        'Content-Type: application/json'
                    ]);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_code >= 200 && $http_code < 300) {
                        $message = 'A new verification link has been sent to <strong style="color:#f4c84a;">' . htmlspecialchars($email) . '</strong>. Please check your inbox and spam folder.';
                        $message_type = 'success';
                    } else {
                        $message = 'Could not send the verification email. API Error: ' . htmlspecialchars($response);
                        $message_type = 'error';
                    }
                } catch (Exception $e) {
                    $message = 'Could not send the verification email. ' . htmlspecialchars($e->getMessage());
                    $message_type = 'error';
                }
            }

        } catch (PDOException $e) {
            $message = 'Database error. Please try again.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification · RENTRACK LGU</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --ink: #0a1d3a;
            --gold: #c99a3f;
            --gold-light: #f3cd7f;
            --white: #ffffff;
            --gray-light: rgba(255,255,255,0.75);
            --gray-mid: rgba(255,255,255,0.45);
            --portal: #3b82f6;
            --portal-light: #60a5fa;
            --portal-rgb: 59, 130, 246;
            --portal-dark: #051b33;
            --portal-mid: #0b3d66;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background:
                radial-gradient(ellipse 55% 45% at 50% 30%, rgba(var(--portal-rgb), 0.30) 0%, transparent 65%),
                radial-gradient(ellipse at 0% 0%, rgba(var(--portal-rgb), 0.14) 0%, transparent 50%),
                radial-gradient(ellipse at 100% 100%, rgba(var(--portal-rgb), 0.10) 0%, transparent 45%),
                linear-gradient(160deg, var(--portal-dark) 0%, var(--ink) 45%, var(--portal-mid) 75%, var(--portal-dark) 100%);
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        .bg-orbs {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            animation: orbFloat 20s ease-in-out infinite alternate;
        }

        .orb-1 {
            width: 500px; height: 500px;
            background: rgba(var(--portal-rgb), 0.16);
            top: -200px; left: -150px;
            animation-duration: 25s;
        }
        .orb-2 {
            width: 400px; height: 400px;
            background: rgba(var(--portal-rgb), 0.12);
            bottom: -150px; right: -100px;
            animation-duration: 20s;
            animation-delay: 3s;
        }

        @keyframes orbFloat {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-30px, 60px) scale(0.9); }
            100% { transform: translate(-50px, -30px) scale(0.95); }
        }

        .top-accent {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--portal), var(--portal-light), var(--portal), transparent);
            background-size: 300% 100%;
            animation: gradientSlide 4s ease-in-out infinite;
            z-index: 10;
        }

        @keyframes gradientSlide {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .resend-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            animation: containerIn 0.8s ease-out;
        }

        @keyframes containerIn {
            0% { opacity: 0; transform: translateY(30px) scale(0.96); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .resend-box {
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 24px;
            padding: 38px 34px 32px;
            box-shadow:
                0 40px 120px rgba(0,0,0,0.5),
                0 0 0 1px rgba(255,255,255,0.03) inset,
                0 0 80px rgba(var(--portal-rgb), 0.10);
            position: relative;
            overflow: hidden;
        }

        .resend-box::before {
            content: '';
            position: absolute;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(var(--portal-rgb), 0.10), transparent 70%);
            top: -100px; right: -80px;
            pointer-events: none;
        }

        .resend-header {
            text-align: center;
            margin-bottom: 26px;
            position: relative;
            z-index: 1;
        }

        .logo-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(var(--portal-rgb), 0.24), rgba(var(--portal-rgb), 0.06));
            border: 2px solid rgba(var(--portal-rgb), 0.40);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 26px;
            color: var(--portal-light);
            box-shadow: 0 0 40px rgba(var(--portal-rgb), 0.16);
            animation: logoPulse 3s ease-in-out infinite;
        }

        @keyframes logoPulse {
            0%, 100% { box-shadow: 0 0 40px rgba(var(--portal-rgb), 0.16); }
            50% { box-shadow: 0 0 60px rgba(var(--portal-rgb), 0.30); }
        }

        .resend-header h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 23px;
            font-weight: 600;
            color: var(--white);
            letter-spacing: -0.3px;
            margin-bottom: 6px;
        }

        .resend-header h1 .gold {
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .resend-header p {
            font-size: 12.5px;
            color: var(--gray-light);
            line-height: 1.55;
            max-width: 320px;
            margin: 0 auto;
        }

        /* Message Alerts */
        .msg {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 13px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 12.5px;
            line-height: 1.55;
            position: relative;
            z-index: 1;
            animation: fadeSlideIn 0.4s ease;
            overflow-wrap: anywhere;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .msg i {
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .msg.error {
            background: rgba(239, 68, 68, 0.10);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fecdd3;
        }

        .msg.error i { color: #f87171; }

        .msg.success {
            background: rgba(52, 211, 153, 0.10);
            border: 1px solid rgba(52, 211, 153, 0.25);
            color: #bbf7d0;
        }

        .msg.success i { color: #4ade80; }

        /* Form */
        .resend-form {
            position: relative;
            z-index: 1;
        }

        .form-group {
            margin-bottom: 16px;
            position: relative;
        }

        .form-group .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-mid);
            font-size: 15px;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .form-group input {
            width: 100%;
            padding: 13px 16px 13px 44px;
            background: rgba(255,255,255,0.04);
            border: 1.5px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            color: var(--white);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-group input::placeholder {
            color: var(--gray-mid);
        }

        .form-group input:focus {
            border-color: var(--portal);
            background: rgba(255,255,255,0.06);
            box-shadow: 0 0 0 4px rgba(var(--portal-rgb), 0.10);
        }

        .form-group input:focus ~ .input-icon {
            color: var(--portal);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--portal), var(--portal-light));
            color: #0a1628;
            font-size: 14.5px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(var(--portal-rgb), 0.35);
        }

        .btn-submit:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.65;
            cursor: wait;
        }

        /* Footer */
        .resend-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.06);
            position: relative;
            z-index: 1;
        }

        .resend-footer a {
            color: var(--gray-light);
            text-decoration: none;
            font-size: 12.5px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.3s ease;
        }

        .resend-footer a:hover {
            color: var(--portal-light);
        }

        .resend-footer a i {
            font-size: 12px;
            transition: transform 0.3s ease;
        }

        .resend-footer a:hover i {
            transform: translateX(-3px);
        }

        /* Info Tip */
        .info-tip {
            margin-top: 18px;
            padding: 11px 13px;
            background: rgba(var(--portal-rgb), 0.08);
            border: 1px solid rgba(var(--portal-rgb), 0.18);
            border-radius: 10px;
            font-size: 11.5px;
            color: rgba(255,255,255,0.65);
            line-height: 1.55;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            position: relative;
            z-index: 1;
        }

        .info-tip i {
            color: var(--portal-light);
            font-size: 13px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            .resend-box {
                padding: 28px 20px 24px;
                border-radius: 18px;
            }
            .resend-header h1 { font-size: 20px; }
            .logo-icon { width: 60px; height: 60px; font-size: 22px; }
            .form-group input { padding: 12px 14px 12px 40px; font-size: 13px; }
            .btn-submit { padding: 12px; font-size: 13.5px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

    <div class="bg-orbs">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
    </div>

    <div class="top-accent"></div>

    <div class="resend-wrapper">
        <div class="resend-box">

            <div class="resend-header">
                <div class="logo-icon">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                </div>
                <h1><span class="gold">Resend</span> Verification Link</h1>
                <p>Enter your registered email address and we'll send you a new verification link.</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="msg <?php echo $message_type; ?>">
                    <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="resend-form" id="resendForm" autocomplete="off">
                <input type="hidden" name="resend" value="1">

                <div class="form-group">
                    <input
                        type="email"
                        name="email"
                        id="emailInput"
                        placeholder="your.email@example.com"
                        value="<?php echo $prefilled_email; ?>"
                        required
                        autofocus
                        autocomplete="email"
                        inputmode="email"
                    >
                    <span class="input-icon"><i class="fa-regular fa-envelope"></i></span>
                </div>

                <button type="submit" class="btn-submit" id="resendBtn">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Send New Verification Link</span>
                </button>
            </form>

            <div class="info-tip">
                <i class="fa-solid fa-circle-info"></i>
                <span>Make sure to check your <strong>Spam</strong> or <strong>Junk</strong> folder if you don't see the email within a few minutes.</span>
            </div>

            <div class="resend-footer">
                <a href="login.php?portal=owner">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to Login
                </a>
            </div>

        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('resendForm');
            const btn = document.getElementById('resendBtn');

            if (form && btn) {
                form.addEventListener('submit', function () {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Sending...</span>';
                });
            }
        })();
    </script>

</body>
</html>