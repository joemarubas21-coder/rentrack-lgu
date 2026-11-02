<?php
/**
 * Email sender for RENTRACK LGU — sends the account verification email
 * through Gmail's SMTP server using PHPMailer.
 *
 * SETUP: copy .env.example to .env and fill in MAIL_USERNAME / MAIL_PASSWORD (Gmail App Password) and SITE_BASE_URL.
 */

require_once __DIR__ . '/env.php';

// Credentials are read from .env / server environment (see .env.example)
define('GMAIL_ADDRESS', env_value('MAIL_USERNAME', ''));
define('GMAIL_APP_PASSWORD', env_value('MAIL_PASSWORD', ''));
define('SITE_BASE_URL', site_base_url());

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends the account verification email.
 *
 * @param string $to_email    recipient's email address
 * @param string $to_name     recipient's full name
 * @param string $token       the verification token to embed in the link
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_verification_email($to_email, $to_name, $token) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_ADDRESS;
        $mail->Password = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(GMAIL_ADDRESS, 'RENTRACK LGU');
        $mail->addAddress($to_email, $to_name);

        $verify_link = rtrim(SITE_BASE_URL, '/') . '/verify.php?token=' . urlencode($token);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your RENTRACK LGU account';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;'>
                <h2 style='color:#0d1b32;'>Welcome to RENTRACK LGU</h2>
                <p>Hi " . htmlspecialchars($to_name) . ",</p>
                <p>Please confirm your email address to activate your account.</p>
                <p style='margin:28px 0;'>
                    <a href='" . $verify_link . "'
                       style='background:#d4af37;color:#0d1b32;padding:12px 24px;
                              text-decoration:none;border-radius:4px;font-weight:bold;
                              display:inline-block;'>
                       Verify My Email
                    </a>
                </p>
                <p style='color:#5b5f70;font-size:.85rem;'>
                    Or copy and paste this link into your browser:<br>
                    " . $verify_link . "
                </p>
                <p style='color:#5b5f70;font-size:.8rem;'>
                    If you didn't create this account, you can safely ignore this email.
                </p>
            </div>
        ";
        $mail->AltBody = "Welcome to RENTRACK LGU. Verify your email by visiting: " . $verify_link;

        $mail->send();
        return ['success' => true, 'error' => null];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}