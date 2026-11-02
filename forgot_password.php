<?php
// forgot_password.php
require_once __DIR__ . '/config/session.php'; app_session_start();
require_once 'config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Direktang i-load ang PHPMailer files mula sa vendor folder
require_once __DIR__ . '/vendor/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer.php';
require_once __DIR__ . '/vendor/SMTP.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_request'])) {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Please enter a valid email address.</div>';
    } else {
        try {
            // Case-insensitive search gamit ang LOWER() sa SQL Query
            $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Mag-generate ng reset token at expiry time (1 hour)
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $update = $conn->prepare("UPDATE users SET reset_token = :token, reset_expires_at = :expires WHERE id = :id");
                $update->execute([
                    ':token'   => hash('sha256', $token),
                    ':expires' => $expires_at,
                    ':id'      => $user['id']
                ]);

                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $reset_link = site_base_url() . "/reset_password.php?token=" . $token;

                // Ipadala ang Email gamit ang PHPMailer (Light/Red Theme)
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = env_value('MAIL_USERNAME', ''); 
                $mail->Password   = env_value('MAIL_PASSWORD', '');   
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom(env_value('MAIL_USERNAME', ''), 'RENTRACK LGU');
                $mail->addAddress($email, $user['fullname']);

                $mail->isHTML(true);
                $mail->Subject = 'Reset Your RENTRACK LGU Password';
                $mail->Body    = "
                <html>
                <body style='font-family: Arial, sans-serif; background-color: #f8fafc; color: #0f172a; padding: 20px;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                        <h2 style='color: #dc2626; margin-top: 0;'>Password Reset Request</h2>
                        <p>Hello <strong>{$user['fullname']}</strong>,</p>
                        <p>We received a request to reset your password for the RENTRACK LGU Owner Portal. Click the button below to proceed:</p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$reset_link}' style='background-color: #dc2626; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Reset Password</a>
                        </div>
                        <p style='color: #64748b; font-size: 13px;'>This link is valid for 1 hour. If you did not request this, please ignore this message.</p>
                    </div>
                </body>
                </html>";

                $mail->send();
                $message = '<div class="alert success"><i class="fa-solid fa-circle-check"></i> We have sent a password reset link to your email address (<strong>' . htmlspecialchars($email) . '</strong>). Please check your inbox or spam folder.</div>';
            } else {
                $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> No account found with that email address. Please make sure you are registered first.</div>';
            }
        } catch (Exception $e) {
            $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Mailer Error: ' . htmlspecialchars($mail->ErrorInfo ?? safe_error($e)) . '</div>';
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#dc2626">
  <title>Forgot Password · RENTRACK LGU</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  
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
        --text-main: #0f172a;
        --text-sub: #334155;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        font-family: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
        background-color: var(--bg-body);
        color: var(--text-main);
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 20px;
        position: relative;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    /* Subtle background gradient */
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
       CARD
    ============================================ */
    .card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        padding: 36px 32px;
        border-radius: 20px;
        width: min(420px, 100%);
        box-shadow: 0 20px 45px -12px rgba(15, 23, 42, 0.10), 0 4px 12px -4px rgba(15, 23, 42, 0.05);
        position: relative;
        animation: panelIn 0.5s ease-out;
    }

    @keyframes panelIn {
        0% { opacity: 0; transform: translateY(15px) scale(0.98); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    .card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        border-radius: 20px 20px 0 0;
        background: linear-gradient(90deg, var(--red-accent), var(--red-deep));
    }

    /* ============================================
       ICON & HEADINGS
    ============================================ */
    .icon-wrapper {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: var(--red-soft);
        border: 2px solid rgba(220, 38, 38, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 26px;
        color: var(--red-accent);
    }

    h2 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 24px;
        font-weight: 800;
        margin-bottom: 8px;
        text-align: center;
        color: var(--text-main);
        letter-spacing: -0.3px;
    }

    p {
        color: var(--text-muted);
        font-size: 13px;
        margin-bottom: 24px;
        text-align: center;
        line-height: 1.6;
        font-weight: 500;
    }

    /* ============================================
       FORM ELEMENTS
    ============================================ */
    .form-group {
        position: relative;
        margin-bottom: 20px;
    }

    .form-group .input-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 15px;
        transition: var(--transition);
        pointer-events: none;
    }

    input {
        width: 100%;
        height: 48px;
        padding: 0 16px 0 44px;
        border-radius: 10px;
        border: 1.5px solid var(--border-color);
        background: var(--bg-card-alt);
        color: var(--text-main);
        font-size: 14px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-weight: 500;
        transition: var(--transition);
        outline: none;
    }

    input::placeholder {
        color: #94a3b8;
        font-weight: 400;
    }

    input:focus {
        border-color: var(--red-accent);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
    }

    input:focus ~ .input-icon {
        color: var(--red-accent);
    }

    button {
        width: 100%;
        height: 48px;
        border-radius: 10px;
        border: 0;
        background: var(--red-accent);
        color: #ffffff;
        font-size: 14px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    button:hover {
        background: var(--red-hover);
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(220, 38, 38, 0.25);
    }

    button:active {
        transform: translateY(0);
    }

    /* ============================================
       ALERTS
    ============================================ */
    .alert {
        padding: 14px 16px;
        border-radius: 10px;
        font-size: 12.5px;
        margin-bottom: 20px;
        line-height: 1.5;
        word-break: break-word;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-weight: 500;
        animation: fadeIn 0.3s ease-out;
    }

    .alert i {
        font-size: 16px;
        flex-shrink: 0;
        margin-top: 1px;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-5px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .alert.success {
        background: rgba(5, 150, 105, 0.1);
        color: #059669;
        border: 1px solid rgba(5, 150, 105, 0.2);
    }

    .alert.error {
        background: var(--red-soft);
        color: var(--red-accent);
        border: 1px solid rgba(220, 38, 38, 0.2);
    }

    /* ============================================
       BACK LINK
    ============================================ */
    .back-link {
        text-align: center;
        margin-top: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: var(--text-muted);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        width: 100%;
        transition: var(--transition);
    }

    .back-link:hover {
        color: var(--red-accent);
        transform: translateX(-2px);
    }

    .back-link i {
        font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="card">
    
    <!-- Icon -->
    <div class="icon-wrapper">
        <i class="fa-solid fa-key"></i>
    </div>

    <h2>Forgot Password?</h2>
    <p>Enter your registered email address below and we will send you instructions to reset your password.</p>
    
    <!-- Alerts -->
    <?php echo $message; ?>
    
    <!-- Form -->
    <form method="POST">
      <div class="form-group">
          <input type="email" name="email" placeholder="Enter your email address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          <span class="input-icon"><i class="fa-regular fa-envelope"></i></span>
      </div>
      <button type="submit" name="reset_request">
          <i class="fa-solid fa-paper-plane"></i> Send Reset Link
      </button>
    </form>
    
    <a href="login.php?portal=owner" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
  </div>
</body>
</html>