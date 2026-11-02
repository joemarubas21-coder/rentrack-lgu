<?php
// verify.php
require_once __DIR__ . '/config/session.php'; app_session_start();
require_once 'config/db.php';
require_once 'config/logger.php';  // ✅ Idinagdag para sa activity logging

$message = '';
$status = 'error';
$verified_name = '';

// Kunin ang token mula sa URL
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);

    try {
        // ✅ Check kung existing ang token at kunin ang user data
        $stmt = $conn->prepare(
            "SELECT id, fullname, username, email, is_verified 
             FROM users 
             WHERE verification_token = :token 
             LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ((int)$user['is_verified'] === 1) {
                // Verified na dati
                $status = 'info';
                $verified_name = $user['fullname'];
                $message = 'Your account is already verified! You may now sign in.';
            } else {
                // ✅ I-update ang user status to verified at i-clear ang token (one-time use)
                $update = $conn->prepare(
                    "UPDATE users 
                     SET is_verified = 1, verification_token = NULL 
                     WHERE id = :id"
                );
                $update->execute([':id' => $user['id']]);

                // ✅ I-LOG ANG SUCCESSFUL VERIFICATION
                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        (int)$user['id'],
                        "Email verified successfully: {$user['email']} ({$user['username']})",
                        'owner',
                        $_SERVER['REMOTE_ADDR'] ?? null
                    );
                }

                $status = 'success';
                $verified_name = $user['fullname'];
                $message = 'Your email has been successfully verified! Redirecting to login page...';
            }
        } else {
            // ❌ Invalid o na-expire na ang token
            $message = 'Invalid or expired verification token. Please request a new verification link.';
            $status = 'error';
        }
    } catch (PDOException $e) {
        $message = 'A system error occurred. Please try again later.';
        $status = 'error';
    }
} else {
    $message = 'No verification token provided. Please check your email for the correct link.';
    $status = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
  <meta name="theme-color" content="#0b1220">
  <meta name="color-scheme" content="dark">
  <title>Account Verification · RENTRACK LGU</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <?php if ($status === 'success' || $status === 'info'): ?>
    <meta http-equiv="refresh" content="3;url=login.php?portal=owner">
  <?php endif; ?>

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
      --success: #34d399;
      --error: #fb7185;
      --info: #38bdf8;
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

    .verify-wrapper {
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

    .verify-card {
      background: rgba(255,255,255,0.04);
      backdrop-filter: blur(24px) saturate(180%);
      -webkit-backdrop-filter: blur(24px) saturate(180%);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 24px;
      padding: 42px 34px 36px;
      box-shadow:
        0 40px 120px rgba(0,0,0,0.5),
        0 0 0 1px rgba(255,255,255,0.03) inset,
        0 0 80px rgba(var(--portal-rgb), 0.10);
      position: relative;
      overflow: hidden;
      text-align: center;
    }

    .verify-card::before {
      content: '';
      position: absolute;
      width: 200px; height: 200px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(var(--portal-rgb), 0.10), transparent 70%);
      top: -100px; right: -80px;
      pointer-events: none;
    }

    /* Icon Circle */
    .status-icon {
      width: 88px;
      height: 88px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 22px;
      font-size: 38px;
      position: relative;
      z-index: 1;
      animation: popIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }

    @keyframes popIn {
      0% { transform: scale(0); opacity: 0; }
      60% { transform: scale(1.1); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }

    .status-icon.success {
      background: linear-gradient(135deg, rgba(52, 211, 153, 0.25), rgba(52, 211, 153, 0.05));
      border: 2px solid rgba(52, 211, 153, 0.45);
      color: var(--success);
      box-shadow: 0 0 50px rgba(52, 211, 153, 0.30);
    }

    .status-icon.error {
      background: linear-gradient(135deg, rgba(251, 113, 133, 0.25), rgba(251, 113, 133, 0.05));
      border: 2px solid rgba(251, 113, 133, 0.45);
      color: var(--error);
      box-shadow: 0 0 50px rgba(251, 113, 133, 0.30);
    }

    .status-icon.info {
      background: linear-gradient(135deg, rgba(56, 189, 248, 0.25), rgba(56, 189, 248, 0.05));
      border: 2px solid rgba(56, 189, 248, 0.45);
      color: var(--info);
      box-shadow: 0 0 50px rgba(56, 189, 248, 0.30);
    }

    .verify-card h2 {
      font-family: 'Fraunces', Georgia, serif;
      font-size: 24px;
      font-weight: 600;
      color: var(--white);
      letter-spacing: -0.3px;
      margin-bottom: 10px;
      position: relative;
      z-index: 1;
    }

    .verify-card h2 .gold {
      background: linear-gradient(135deg, var(--gold), var(--gold-light));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .verify-card p {
      color: var(--gray-light);
      font-size: 13.5px;
      line-height: 1.65;
      margin-bottom: 8px;
      position: relative;
      z-index: 1;
    }

    .verify-card p strong {
      color: var(--portal-light);
      font-weight: 700;
    }

    /* Auto-redirect note */
    .redirect-note {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-top: 14px;
      padding: 7px 14px;
      background: rgba(var(--portal-rgb), 0.10);
      border: 1px solid rgba(var(--portal-rgb), 0.20);
      border-radius: 999px;
      font-size: 11.5px;
      color: var(--gray-light);
      position: relative;
      z-index: 1;
    }

    .redirect-note i {
      color: var(--portal-light);
      font-size: 11px;
    }

    /* Buttons */
    .btn-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      width: 100%;
      margin-top: 22px;
      padding: 13px 24px;
      background: linear-gradient(135deg, var(--gold), var(--gold-light));
      color: #07101f;
      text-decoration: none;
      font-weight: 700;
      font-size: 14px;
      border-radius: 12px;
      border: none;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      z-index: 1;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 32px rgba(244, 200, 74, 0.35);
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      width: 100%;
      margin-top: 10px;
      padding: 12px 24px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.12);
      color: var(--gray-light);
      text-decoration: none;
      font-weight: 600;
      font-size: 13px;
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      z-index: 1;
    }

    .btn-secondary:hover {
      background: rgba(var(--portal-rgb), 0.12);
      border-color: rgba(var(--portal-rgb), 0.35);
      color: var(--portal-light);
      transform: translateY(-1px);
    }

    @media (max-width: 480px) {
      .verify-card {
        padding: 32px 22px 28px;
        border-radius: 18px;
      }
      .verify-card h2 { font-size: 20px; }
      .status-icon { width: 74px; height: 74px; font-size: 32px; margin-bottom: 18px; }
      .btn-primary, .btn-secondary { padding: 12px 20px; font-size: 13px; }
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

  <div class="verify-wrapper">
    <div class="verify-card">

      <?php if ($status === 'success'): ?>

        <!-- ✅ SUCCESS VIEW -->
        <div class="status-icon success">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <h2>Email <span class="gold">Verified!</span></h2>
        <p>
          Congratulations<?php echo $verified_name ? ', <strong>' . htmlspecialchars($verified_name) . '</strong>' : ''; ?>!
          Your email has been successfully verified.
        </p>
        <p style="font-size:12.5px;color:var(--gray-mid);">You may now log in to your Owner Portal account.</p>

        <div class="redirect-note">
          <i class="fa-solid fa-spinner fa-spin"></i>
          <span>Redirecting to login in 3 seconds...</span>
        </div>

        <a href="login.php?portal=owner" class="btn-primary">
          <i class="fa-solid fa-right-to-bracket"></i>
          Go to Owner Login
        </a>

      <?php elseif ($status === 'info'): ?>

        <!-- ℹ️ ALREADY VERIFIED VIEW -->
        <div class="status-icon info">
          <i class="fa-solid fa-circle-info"></i>
        </div>
        <h2>Account <span class="gold">Already Verified</span></h2>
        <p><?php echo htmlspecialchars($message); ?></p>

        <div class="redirect-note">
          <i class="fa-solid fa-spinner fa-spin"></i>
          <span>Redirecting to login in 3 seconds...</span>
        </div>

        <a href="login.php?portal=owner" class="btn-primary">
          <i class="fa-solid fa-right-to-bracket"></i>
          Go to Owner Login
        </a>

      <?php else: ?>

        <!-- ❌ ERROR VIEW -->
        <div class="status-icon error">
          <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <h2>Verification <span class="gold">Failed</span></h2>
        <p><?php echo htmlspecialchars($message); ?></p>

        <?php if (!empty($token)): ?>
          <p style="font-size:12px;color:var(--gray-mid);margin-top:10px;">
            The link may have already been used or expired.
          </p>
        <?php endif; ?>

        <a href="resend_verification.php" class="btn-primary">
          <i class="fa-solid fa-paper-plane"></i>
          Resend Verification Link
        </a>
        <a href="login.php?portal=owner" class="btn-secondary">
          <i class="fa-solid fa-arrow-left"></i>
          Back to Login
        </a>

      <?php endif; ?>

    </div>
  </div>

</body>
</html>