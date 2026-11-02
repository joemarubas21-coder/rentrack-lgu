<?php
// reset_password.php
require_once __DIR__ . '/config/session.php'; app_session_start();
require_once 'config/db.php';

$message = '';
$valid_token = false;
$token = trim($_GET['token'] ?? '');

if (!empty($token)) {
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_expires_at > NOW() LIMIT 1");
        $stmt->execute([':token' => hash('sha256', $token)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $valid_token = true;
        } else {
            $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Invalid or expired reset token. Please request a new one.</div>';
        }
    } catch (PDOException $e) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> System error occurred.</div>';
    }
} else {
    $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> No reset token provided.</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token && isset($_POST['update_password'])) {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($new_password !== $confirm_password) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Passwords do not match.</div>';
    } elseif (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password) || !preg_match('/[^A-Za-z0-9]/', $new_password)) {
        $message = '<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> Password does not meet the security requirements.</div>';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $update = $conn->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_expires_at = NULL WHERE id = :id");
        $update->execute([
            ':password' => $hashed_password,
            ':id'       => $user['id']
        ]);

        $valid_token = false;
        $message = '<div class="alert success"><i class="fa-solid fa-circle-check"></i> Password reset successfully! Redirecting to login page...</div>';
        header("refresh:3;url=login.php?portal=owner");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password · RENTRACK LGU</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <style>
    :root {
      --bg: #07101f;
      --card-bg: #0d1b2f;
      --border: rgba(255, 255, 255, 0.1);
      --text: #f8fafc;
      --muted: #94a3b8;
      --gold: #f4c84a;
      --gold2: #ffd96a;
      --green: #34d399;
      --red: #fb7185;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', sans-serif;
      background: radial-gradient(circle at 50% 10%, rgba(56, 189, 248, 0.08), transparent 40%),
                  linear-gradient(135deg, #050b16 0%, #0a1528 52%, #0e2039 100%);
      color: var(--text);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
    }

    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      padding: 35px 30px;
      border-radius: 24px;
      width: min(440px, 100%);
      box-shadow: 0 25px 60px rgba(0,0,0,0.5);
      backdrop-filter: blur(16px);
    }

    .icon-header {
      width: 54px;
      height: 54px;
      background: rgba(244, 200, 74, 0.1);
      border: 1px solid rgba(244, 200, 74, 0.2);
      border-radius: 16px;
      display: grid;
      place-items: center;
      color: var(--gold);
      font-size: 20px;
      margin: 0 auto 16px;
    }

    h2 { font-size: 22px; margin-bottom: 6px; text-align: center; font-weight: 800; }
    p.desc { color: var(--muted); font-size: 13px; margin-bottom: 22px; text-align: center; line-height: 1.5; }

    .form-group { margin-bottom: 18px; text-align: left; }
    
    label {
      display: flex;
      align-items: center;
      gap: 6px;
      color: #dbe4ef;
      font-size: 11px;
      font-weight: 700;
      margin-bottom: 7px;
    }
    label i { color: var(--gold); }

    .input-wrap { position: relative; }

    .input-wrap .field-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #64748b;
      font-size: 13px;
    }

    input {
      width: 100%;
      height: 46px;
      padding: 0 42px 0 38px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.035);
      color: #fff;
      font-size: 13px;
      outline: none;
      transition: 0.2s ease;
    }

    input:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(244, 200, 74, 0.08);
      background: rgba(255,255,255,0.05);
    }

    .toggle-pw {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 32px;
      height: 32px;
      border: 0;
      border-radius: 8px;
      background: transparent;
      color: #64748b;
      cursor: pointer;
      transition: 0.2s;
    }
    .toggle-pw:hover { color: #fff; }

    /* PROFESSIONAL REQUIREMENTS BOX */
    .req-box {
      display: none;
      background: rgba(255, 255, 255, 0.025);
      border: 1px solid rgba(255, 255, 255, 0.07);
      border-radius: 12px;
      padding: 12px 14px;
      margin-top: 10px;
    }

    .req-title {
      font-size: 10px;
      font-weight: 700;
      color: var(--muted);
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .req-list {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .req-list li {
      font-size: 11px;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: 0.2s;
    }

    .req-list li i {
      font-size: 10px;
      width: 12px;
      text-align: center;
    }

    .req-list li.met {
      color: var(--green);
      font-weight: 500;
    }

    .req-list li.met i {
      color: var(--green);
    }

    /* STRENGTH METER */
    .strength-meter {
      height: 4px;
      background: rgba(255, 255, 255, 0.08);
      border-radius: 999px;
      margin-top: 10px;
      overflow: hidden;
    }

    .strength-bar {
      height: 100%;
      width: 0%;
      background: var(--red);
      transition: all 0.3s ease;
      border-radius: 999px;
    }

    .hint-text {
      min-height: 16px;
      margin-top: 6px;
      font-size: 11px;
    }

    .hint-text.match { color: #6ee7b7; }
    .hint-text.no-match { color: #fda4af; font-weight: 600; }

    button[type="submit"] {
      width: 100%;
      height: 48px;
      border-radius: 12px;
      border: 0;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      color: #07101f;
      font-size: 13px;
      font-weight: 800;
      cursor: pointer;
      margin-top: 10px;
      box-shadow: 0 10px 25px rgba(244, 200, 74, 0.15);
      transition: 0.2s;
    }

    button[type="submit"]:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 30px rgba(244, 200, 74, 0.22);
    }

    .alert {
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 12px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
      line-height: 1.4;
    }

    .alert.success { background: rgba(52,211,153,0.1); color: #34d399; border: 1px solid rgba(52,211,153,0.2); }
    .alert.error { background: rgba(251,113,133,0.1); color: #fb7185; border: 1px solid rgba(251,113,133,0.2); }

    .back-link {
      text-align: center;
      margin-top: 20px;
      display: inline-block;
      width: 100%;
      color: var(--gold);
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
    }

    .back-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon-header">
      <i class="fa-solid fa-key"></i>
    </div>
    <h2>Create New Password</h2>
    <p class="desc">Please enter your new strong password below.</p>
    
    <?php echo $message; ?>

    <?php if ($valid_token): ?>
    <form method="POST" id="resetForm">
      
      <div class="form-group">
        <label for="password"><i class="fa-solid fa-lock"></i> New Password</label>
        <div class="input-wrap">
          <i class="field-icon fa-solid fa-key"></i>
          <input type="password" id="password" name="password" placeholder="Create a strong password" required autocomplete="new-password">
          <button type="button" class="toggle-pw" data-target="password" aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>

        <div class="strength-meter" id="strengthMeter">
          <div class="strength-bar" id="strengthBar"></div>
        </div>

        <!-- PROFESSIONAL REQUIREMENTS PANEL -->
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
        <label for="confirm_password"><i class="fa-solid fa-circle-check"></i> Confirm Password</label>
        <div class="input-wrap">
          <i class="field-icon fa-solid fa-key"></i>
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required autocomplete="new-password">
          <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>
        <div class="hint-text" id="pwMatchHint"></div>
      </div>

      <button type="submit" name="update_password" id="submitBtn">Update Password</button>
    </form>
    <?php endif; ?>

    <a href="login.php?portal=owner" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
  </div>

  <script>
    (function () {
      'use strict';

      /* Show/Hide password toggle */
      document.querySelectorAll('.toggle-pw').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const input = document.getElementById(this.getAttribute('data-target'));
          if (!input) return;

          const icon = this.querySelector('i');
          const hidden = input.type === 'password';

          input.type = hidden ? 'text' : 'password';

          if (icon) {
            icon.className = hidden ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
          }
        });
      });

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

        /* Dynamic Strength Bar */
        const percentage = (metCount / 5) * 100;
        if (strengthBar) {
          strengthBar.style.width = percentage + '%';
          if (metCount <= 2) {
            strengthBar.style.background = '#fb7185'; // Red
          } else if (metCount <= 4) {
            strengthBar.style.background = '#f4c84a'; // Gold
          } else {
            strengthBar.style.background = '#34d399'; // Green
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
        });
      }

      if (confirmInput) {
        confirmInput.addEventListener('input', checkMatch);
      }
    })();
  </script>
</body>
</html>