<?php
// index.php — RENTRACK LGU Landing Page (Dashboard-matched light theme)

$latest_unit = null;
$activePermits = null;
$pendingReview = null;
$complianceRate = null;

try {
    $host = 'localhost';
    $dbname = 'rentrack_lgu';
    $username = 'root';
    $password = '';

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT * FROM rentals WHERE is_deleted = 0 ORDER BY id DESC LIMIT 1");
    $latest_unit = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT COUNT(*) FROM rentals WHERE is_deleted = 0 AND status = 'Approved'");
    $activePermits = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM rentals WHERE is_deleted = 0 AND status = 'Pending'");
    $pendingReview = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM rentals WHERE is_deleted = 0");
    $totalForCompliance = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM rentals WHERE is_deleted = 0 AND compliance_status = 'Compliant'");
    $compliantCount = (int) $stmt->fetchColumn();

    $complianceRate = $totalForCompliance > 0 ? round(($compliantCount / $totalForCompliance) * 100) : null;

} catch (PDOException $e) {
    $latest_unit = null;
    $activePermits = null;
    $pendingReview = null;
    $complianceRate = null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes,viewport-fit=cover" />
  <title>RENTRACK LGU | Unified Rental Registration, Tracking &amp; Compliance</title>
  <meta name="description" content="A web-based unified system for registration, tracking and compliance of all rental types in the LGU." />

  <meta name="theme-color" content="#dc2626" />
  <meta name="color-scheme" content="light" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="RENTRACK LGU" />
  <meta name="format-detection" content="telephone=no" />
  <link rel="apple-touch-icon" href="assets/images/images.png" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <style>
    /* ============================================
       DESIGN SYSTEM — Dashboard palette
    ============================================ */
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
      --emerald-accent: #059669;
      --violet-accent: #7c3aed;
      --blue-accent: #2563eb;

      --text-main: #0f172a;
      --text-sub: #334155;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --border-strong: #cbd5e1;

      --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
      --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);

      --card-radius: 20px;
      --max-width: 1200px;
      --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      --header-h: 76px;
      --container-pad: 24px;

      --safe-top: env(safe-area-inset-top, 0px);
      --safe-right: env(safe-area-inset-right, 0px);
      --safe-bottom: env(safe-area-inset-bottom, 0px);
      --safe-left: env(safe-area-inset-left, 0px);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    a, button { touch-action: manipulation; }
    a { color: inherit; text-decoration: none; }
    img { max-width: 100%; display: block; }

    body {
      background-color: var(--bg-body);
      font-family: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
      color: var(--text-main);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
    }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 20px; }

    .container {
      width: 100%; max-width: var(--max-width); margin: 0 auto;
      padding: 0 var(--container-pad); position: relative; z-index: 10;
    }

    /* ============================================
       LIGHT BG — pure flat like dashboard
    ============================================ */
    .bg-canvas {
      position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
    }
    .bg-gradient {
      position: absolute; inset: 0;
      background: var(--bg-body);
    }
    /* Very subtle color accents (like dashboard's welcome-banner tints) */
    .bg-gradient::before,
    .bg-gradient::after {
      content: '';
      position: absolute;
      border-radius: 50%;
      pointer-events: none;
    }
    .bg-gradient::before {
      top: -180px; right: -100px;
      width: 480px; height: 480px;
      background: radial-gradient(circle, rgba(220, 38, 38, 0.05) 0%, transparent 65%);
    }
    .bg-gradient::after {
      bottom: -180px; left: -100px;
      width: 520px; height: 520px;
      background: radial-gradient(circle, rgba(8, 145, 178, 0.04) 0%, transparent 65%);
    }

    /* ============================================
       BUTTONS
    ============================================ */
    .btn {
      display: inline-flex; align-items: center; justify-content: center;
      gap: 10px; padding: 12px 26px;
      border-radius: 999px; font-family: inherit;
      font-weight: 700; font-size: 14px;
      cursor: pointer; border: 1px solid transparent;
      transition: var(--transition); white-space: nowrap;
    }
    .btn-primary {
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      color: #fff;
      box-shadow: 0 8px 20px -6px rgba(220, 38, 38, 0.45);
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      background: linear-gradient(90deg, var(--red-accent), var(--red-hover) 55%, var(--red-accent));
      box-shadow: 0 12px 26px -6px rgba(220, 38, 38, 0.55);
    }
    .btn-secondary {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      box-shadow: var(--shadow-soft);
    }
    .btn-secondary:hover {
      transform: translateY(-2px);
      background: var(--bg-card-alt);
      border-color: var(--red-accent);
      color: var(--red-accent);
      box-shadow: var(--shadow-lift);
    }
    .btn-white {
      background: #ffffff;
      color: var(--red-accent);
      border-color: #ffffff;
      box-shadow: 0 6px 16px -4px rgba(0, 0, 0, 0.25);
    }
    .btn-white:hover {
      transform: translateY(-2px);
      background: #fff7f7;
      color: var(--red-deep);
      box-shadow: 0 10px 22px -4px rgba(0, 0, 0, 0.3);
    }
    .btn-ghost-white {
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: #ffffff;
    }
    .btn-ghost-white:hover {
      background: rgba(255, 255, 255, 0.25);
      border-color: #ffffff;
      transform: translateY(-2px);
    }

    /* ============================================
       HEADER — RED GRADIENT (like dashboard's top bar)
    ============================================ */
    .site-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      border-bottom: 1px solid rgba(0, 0, 0, 0.1);
      box-shadow: 0 4px 15px rgba(220, 38, 38, 0.2);
      transition: var(--transition);
      padding-top: var(--safe-top);
    }
    .site-header.scrolled {
      box-shadow: 0 10px 30px rgba(153, 27, 27, 0.35);
    }
    .site-header .container {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; height: var(--header-h);
      padding-left: calc(var(--container-pad) + var(--safe-left));
      padding-right: calc(var(--container-pad) + var(--safe-right));
    }

    .brand {
      display: flex; align-items: center; gap: 12px;
      min-width: 0; flex: 1 1 auto; color: #fff;
    }
    .brand .logo {
      width: 44px; height: 44px; border-radius: 12px;
      background: #ffffff; color: var(--red-accent);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      overflow: hidden;
    }
    .brand .logo img {
      width: 100%; height: 100%; object-fit: cover; border-radius: 12px;
    }
    .brand .name {
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800; font-size: 17px; letter-spacing: 0.3px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      color: #ffffff;
    }
    .brand .name .gold { color: #fde68a; }
    .brand .tagline {
      font-size: 10px; font-weight: 600; letter-spacing: 1px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.82);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .nav-links { display: flex; align-items: center; gap: 28px; }
    .nav-links .nav-link-login { display: none; }
    .nav-links a {
      font-size: 13.5px; font-weight: 600;
      color: rgba(255, 255, 255, 0.9);
      transition: var(--transition); position: relative;
    }
    .nav-links a::after {
      content: ''; position: absolute; left: 0; bottom: -5px;
      width: 0; height: 2px; background: #ffffff; border-radius: 2px;
      transition: var(--transition);
    }
    .nav-links a:hover { color: #ffffff; }
    .nav-links a:hover::after { width: 100%; }

    .nav-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .nav-actions .btn-white { padding: 9px 20px; font-size: 13px; }

    .mobile-toggle {
      display: none; align-items: center; justify-content: center;
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #ffffff;
      width: 42px; height: 42px;
      border-radius: 10px; font-size: 18px;
      cursor: pointer; flex-shrink: 0;
    }
    .mobile-toggle:active { background: rgba(255, 255, 255, 0.25); }

    @media (max-width: 900px) {
      .nav-links {
        position: fixed;
        top: calc(var(--header-h) + var(--safe-top));
        left: 0; right: 0;
        background: #ffffff;
        flex-direction: column; align-items: stretch;
        padding: 18px var(--container-pad) calc(18px + var(--safe-bottom));
        display: none;
        border-bottom: 1px solid var(--border-color);
        box-shadow: 0 20px 30px -15px rgba(15, 23, 42, 0.15);
        max-height: calc(100vh - var(--header-h) - var(--safe-top));
        max-height: calc(100dvh - var(--header-h) - var(--safe-top));
        overflow-y: auto; -webkit-overflow-scrolling: touch;
        padding-left: calc(var(--container-pad) + var(--safe-left));
        padding-right: calc(var(--container-pad) + var(--safe-right));
      }
      .nav-links.open { display: flex; }
      .nav-links a { padding: 12px 4px; font-size: 15px; color: var(--text-sub); }
      .nav-links a:hover { color: var(--red-accent); }
      .nav-links a::after { background: var(--red-accent); }
      .nav-links .nav-link-login {
        display: block; margin-top: 8px; padding-top: 16px;
        border-top: 1px solid var(--border-color);
        color: var(--red-accent); font-weight: 800;
      }
      .mobile-toggle { display: flex; }
      .nav-actions .btn-white { display: none; }
    }

    /* ============================================
       HERO
    ============================================ */
    .hero {
      padding: calc(var(--header-h) + 60px) 0 70px;
      display: flex; align-items: center;
      position: relative; z-index: 2;
    }
    .hero .container {
      display: flex; flex-direction: column;
      justify-content: center; align-items: center; gap: 0;
    }
    .hero-visual {
      position: relative;
      display: flex; flex-direction: column;
      justify-content: center; align-items: center;
      padding: 14px; width: 100%; max-width: 1300px;
    }
    .hero-glow {
      position: absolute; inset: -60px;
      background:
        radial-gradient(circle at 25% 20%, rgba(220, 38, 38, 0.08), transparent 60%),
        radial-gradient(circle at 80% 85%, rgba(8, 145, 178, 0.08), transparent 60%);
      filter: blur(50px); z-index: 0; pointer-events: none;
    }

    .hero-caption {
      display: flex; flex-direction: column; align-items: center;
      gap: 16px; position: relative; z-index: 5;
      margin-bottom: 28px;
      opacity: 0; transform: translateY(-20px);
      animation: heroCaptionIn 0.9s cubic-bezier(0.4, 0, 0.2, 1) 0.2s forwards;
    }
    @keyframes heroCaptionIn { to { opacity: 1; transform: translateY(0); } }

    .hero-badge {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.28);
      color: var(--red-deep);
      padding: 7px 18px 7px 14px;
      border-radius: 999px;
      font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.4px;
      box-shadow: 0 6px 18px -8px rgba(220, 38, 38, 0.35);
    }
    .hero-badge .live-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: var(--red-accent);
      box-shadow: 0 0 8px 2px rgba(220, 38, 38, 0.7);
      animation: liveDotPulse 2s ease-in-out infinite;
    }
    @keyframes liveDotPulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50%      { opacity: 0.4; transform: scale(0.7); }
    }

    .hero-tagline {
      text-align: center; max-width: 680px;
      font-size: 19px; font-weight: 500; line-height: 1.65;
      color: var(--text-sub);
      position: relative; z-index: 2; letter-spacing: 0.1px;
    }
    .hero-tagline .highlight {
      font-weight: 800;
      background: linear-gradient(135deg, var(--red-accent), var(--red-deep));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .hero-mock {
      position: relative; z-index: 2;
      width: 100%; max-width: 1200px;
    }
    .hero-mock::before {
      content: ''; position: absolute; inset: -1.5px;
      border-radius: 30px; padding: 1.5px;
      background: linear-gradient(135deg, rgba(220, 38, 38, 0.5), rgba(217, 119, 6, 0.35) 40%, rgba(255, 255, 255, 0.4) 70%);
      -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor; mask-composite: exclude;
      z-index: 1; pointer-events: none;
    }
    .hero-photo {
      position: relative; z-index: 2;
      width: 100%; aspect-ratio: 16 / 9;
      overflow: hidden; border-radius: 28px;
      background: #fff;
      box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.25);
    }
    .hero-photo img,
    .hero-photo video {
      width: 100%; height: 100%;
      object-fit: cover; object-position: center 40%;
      display: block;
      filter: brightness(1.02) contrast(1.02) saturate(1.05);
    }
    .hero-photo::after {
      content: ''; position: absolute; inset: 0;
      box-shadow: inset 0 0 50px 5px rgba(15, 23, 42, 0.10);
      background:
        linear-gradient(to top, rgba(15, 23, 42, 0.15), transparent 30%),
        linear-gradient(to bottom, rgba(15, 23, 42, 0.05), transparent 25%);
      pointer-events: none;
    }
    .hero-mock-badge {
      position: absolute; left: 24px; bottom: -20px; z-index: 3;
      width: 52px; height: 52px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      background: #ffffff;
      border: 2px solid var(--border-color);
      box-shadow: 0 12px 30px -8px rgba(15, 23, 42, 0.25);
      color: var(--red-accent); font-size: 18px;
    }
    .hero-mock-dot {
      position: absolute; top: 18px; right: 18px; z-index: 3;
      width: 14px; height: 14px; border-radius: 50%;
      background: var(--emerald-accent);
      box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.18), 0 0 14px rgba(5, 150, 105, 0.8);
      animation: mockDotPulse 2.4s ease-in-out infinite;
    }
    @keyframes mockDotPulse {
      0%, 100% { box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.18), 0 0 14px rgba(5, 150, 105, 0.8); }
      50%      { box-shadow: 0 0 0 8px rgba(5, 150, 105, 0.10), 0 0 20px rgba(5, 150, 105, 1); }
    }

    /* ============================================
       SECTIONS
    ============================================ */
    section { padding: 90px 0; position: relative; z-index: 2; }
    .section-head { text-align: center; max-width: 640px; margin: 0 auto 50px; }
    .section-head h2 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 34px; font-weight: 800;
      color: var(--text-main); margin-bottom: 12px;
      letter-spacing: -0.5px;
    }
    .section-head p { color: var(--text-muted); font-size: 15px; line-height: 1.6; }
    .section-eyebrow {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 11px; font-weight: 800;
      color: var(--red-accent);
      text-transform: uppercase; letter-spacing: 1.2px;
      margin-bottom: 10px;
    }
    .gradient-text {
      background: linear-gradient(135deg, var(--red-accent), var(--red-deep));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* Stats */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--card-radius);
      padding: 26px 22px;
      text-align: center;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
      position: relative;
    }
    .stat-card::before {
      content: ''; position: absolute; top: 0; left: 50%;
      transform: translateX(-50%);
      width: 44px; height: 3px; border-radius: 0 0 4px 4px;
      background: linear-gradient(90deg, var(--red-accent), var(--red-deep));
      opacity: 0.85;
    }
    .stat-card:hover {
      transform: translateY(-4px);
      border-color: rgba(220, 38, 38, 0.35);
      box-shadow: var(--shadow-lift);
    }
    .stat-card .number {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 36px; font-weight: 800;
      background: linear-gradient(135deg, var(--red-accent), var(--red-deep));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 4px;
    }
    .stat-card .label { font-size: 13px; color: var(--text-muted); font-weight: 600; }

    /* Features */
    .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .feature-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--card-radius);
      padding: 30px 26px;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
    }
    .feature-card:hover {
      transform: translateY(-6px);
      border-color: rgba(220, 38, 38, 0.35);
      box-shadow: var(--shadow-lift);
    }
    .feature-card .icon {
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; margin-bottom: 18px;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.15);
      color: var(--red-accent);
      transition: var(--transition);
    }
    .feature-card:hover .icon { transform: scale(1.08) rotate(-4deg); }
    .feature-card .icon.tone-teal { background: rgba(8,145,178,0.10); border-color: rgba(8,145,178,0.20); color: var(--teal-accent); }
    .feature-card .icon.tone-emerald { background: rgba(5,150,105,0.10); border-color: rgba(5,150,105,0.20); color: var(--emerald-accent); }
    .feature-card .icon.tone-gold { background: rgba(217,119,6,0.10); border-color: rgba(217,119,6,0.20); color: var(--gold-accent); }
    .feature-card .icon.tone-violet { background: rgba(124,58,237,0.10); border-color: rgba(124,58,237,0.20); color: var(--violet-accent); }
    .feature-card h3 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 17px; font-weight: 800; color: var(--text-main);
      margin-bottom: 8px;
    }
    .feature-card p { font-size: 13.5px; color: var(--text-muted); line-height: 1.6; }

    /* Steps */
    .steps-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; text-align: center; }
    .step {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--card-radius);
      padding: 28px 20px;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
    }
    .step:hover { border-color: rgba(220, 38, 38, 0.35); box-shadow: var(--shadow-lift); }
    .step .num {
      width: 48px; height: 48px; border-radius: 50%;
      background: var(--red-soft);
      border: 1.5px solid rgba(220, 38, 38, 0.35);
      color: var(--red-accent);
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800; font-size: 18px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 14px;
    }
    .step h3 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 16px; font-weight: 800; color: var(--text-main);
      margin-bottom: 6px;
    }
    .step p { font-size: 12.5px; color: var(--text-muted); line-height: 1.55; }

    /* Portals */
    .portals-scroll {
      display: flex; gap: 20px;
      padding: 10px 4px 18px;
      -webkit-overflow-scrolling: touch;
      scroll-snap-type: x proximity;
      justify-content: center; flex-wrap: wrap;
    }
    .portal-preview {
      flex: 0 0 200px;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--card-radius);
      padding: 26px 20px;
      text-align: center;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
      scroll-snap-align: start;
    }
    .portal-preview:hover {
      transform: translateY(-4px);
      border-color: rgba(220, 38, 38, 0.35);
      box-shadow: var(--shadow-lift);
    }
    .portal-preview .title {
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800; font-size: 14px; color: var(--text-main);
    }
    .portal-preview .desc {
      font-size: 11.5px; color: var(--text-muted); margin-top: 4px;
    }

    /* CTA */
    .cta-banner {
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      border: 1px solid rgba(0, 0, 0, 0.1);
      border-radius: 28px;
      padding: 56px 40px;
      text-align: center;
      box-shadow: 0 30px 60px -20px rgba(220, 38, 38, 0.35);
      position: relative; overflow: hidden;
    }
    .cta-banner::before {
      content: ''; position: absolute; inset: 0;
      background:
        radial-gradient(circle at 15% 20%, rgba(255,255,255,0.12), transparent 55%),
        radial-gradient(circle at 85% 80%, rgba(255,255,255,0.08), transparent 55%);
      pointer-events: none;
    }
    .cta-banner > * { position: relative; z-index: 1; }
    .cta-banner h2 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 30px; font-weight: 800; color: #fff;
      margin-bottom: 10px; letter-spacing: -0.5px;
    }
    .cta-banner p { font-size: 15px; color: rgba(255,255,255,0.9); margin-bottom: 26px; }

    /* Reveal */
    .reveal { opacity: 0; transform: translateY(30px); transition: all 0.8s ease; }
    .reveal.visible { opacity: 1; transform: translateY(0); }

    /* Footer (light, matching dashboard) */
    .site-footer {
      background: var(--bg-card);
      color: var(--text-muted);
      padding: 56px 0 26px;
      padding-bottom: calc(26px + var(--safe-bottom));
      margin-top: auto;
      border-top: 1px solid var(--border-color);
      position: relative; z-index: 2;
    }
    .footer-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 40px; margin-bottom: 36px; }
    .site-footer .brand { color: var(--text-main); }
    .site-footer .brand .name { color: var(--text-main); }
    .site-footer .brand .name .gold { color: var(--red-accent); }
    .site-footer .brand .tagline { color: var(--text-muted); }
    .site-footer h4 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 12px; font-weight: 800;
      text-transform: uppercase; letter-spacing: 1.2px;
      color: var(--text-main); margin-bottom: 16px;
    }
    .site-footer p, .site-footer a {
      font-size: 13px; color: var(--text-muted);
      margin-bottom: 8px; transition: var(--transition);
    }
    .site-footer a:hover { color: var(--red-accent); }
    .site-footer p i { color: var(--red-accent); margin-right: 6px; }
    .footer-bottom {
      display: flex; justify-content: space-between;
      border-top: 1px solid var(--border-color);
      padding-top: 20px; font-size: 12.5px; color: var(--text-muted);
    }
    .footer-bottom a { color: var(--text-muted); }
    .footer-bottom a:hover { color: var(--red-accent); }

    /* Back top */
    .back-top {
      position: fixed;
      right: calc(24px + var(--safe-right));
      bottom: calc(24px + var(--safe-bottom));
      z-index: 50;
      width: 46px; height: 46px; border-radius: 50%;
      background: linear-gradient(135deg, var(--red-accent), var(--red-deep));
      color: #fff; border: none;
      cursor: pointer; opacity: 0; visibility: hidden;
      transition: var(--transition);
      box-shadow: 0 8px 20px -4px rgba(220, 38, 38, 0.5);
      font-size: 14px;
    }
    .back-top.show { opacity: 1; visibility: visible; }
    .back-top:hover { background: linear-gradient(135deg, var(--red-hover), var(--red-accent)); transform: translateY(-2px); }

    /* ============================================
       RESPONSIVE
    ============================================ */
    @media (max-width: 1024px) {
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .features-grid { grid-template-columns: repeat(2, 1fr); }
      .steps-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 900px) {
      .features-grid, .steps-grid, .stats-grid { grid-template-columns: 1fr; }
      .footer-grid { grid-template-columns: 1fr 1fr; }
      .hero { padding: calc(var(--header-h) + var(--safe-top) + 30px) 0 40px; }
      .hero-tagline { font-size: 16px; max-width: 560px; }
      .hero-caption { margin-bottom: 22px; gap: 14px; }
      .section-head h2 { font-size: 28px; }
      section { padding: 64px 0; }
    }
    @media (max-width: 640px) {
      :root { --header-h: 66px; --container-pad: 16px; }

      .brand .logo { width: 38px; height: 38px; border-radius: 10px; }
      .brand .name { font-size: 15px; }
      .brand .tagline { font-size: 9px; letter-spacing: 0.5px; }

      .nav-actions { gap: 6px; }
      .mobile-toggle { width: 40px; height: 40px; font-size: 17px; }

      .hero { padding: calc(var(--header-h) + var(--safe-top) + 22px) 0 32px; }
      .hero-badge { font-size: 10px; padding: 6px 14px 6px 11px; }
      .hero-tagline { font-size: 14px; line-height: 1.6; padding: 0 8px; max-width: 420px; }
      .hero-caption { margin-bottom: 18px; gap: 12px; }

      .hero-visual { padding: 12px; }
      .hero-photo { aspect-ratio: 16 / 10; border-radius: 20px; }
      .hero-mock-badge { width: 44px; height: 44px; font-size: 15px; left: 16px; bottom: -14px; }
      .hero-mock-dot { width: 11px; height: 11px; top: 12px; right: 12px; }

      section { padding: 48px 0; }
      .section-head { margin-bottom: 34px; }
      .section-head h2 { font-size: 24px; }
      .section-head p { font-size: 13.5px; }

      .stat-card { padding: 20px 16px; }
      .stat-card .number { font-size: 28px; }

      .feature-card { padding: 24px 20px; }
      .step { padding: 22px 16px; }

      .portals-scroll { gap: 14px; }
      .portal-preview { flex-basis: 168px; padding: 22px 16px; }

      .cta-banner { padding: 40px 22px; border-radius: 22px; }
      .cta-banner h2 { font-size: 22px !important; }
      .cta-banner p { font-size: 13.5px !important; }

      .footer-grid { grid-template-columns: 1fr; gap: 28px; }
      .footer-bottom { flex-direction: column; gap: 12px; text-align: center; }

      .back-top { width: 42px; height: 42px; font-size: 13px; }
    }
    @media (max-width: 380px) {
      .brand { gap: 8px; }
      .brand .tagline { display: none; }
      .section-head h2 { font-size: 22px; }
      .hero-visual { padding: 8px; }
      .hero-mock-badge { width: 38px; height: 38px; font-size: 13px; left: 12px; bottom: -12px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .hero-caption { animation: none !important; opacity: 1 !important; transform: none !important; }
      .live-dot, .hero-mock-dot { animation: none !important; }
      html { scroll-behavior: auto; }
    }

    /* ============================================
       PAGE LOADER — LIGHT THEME
    ============================================ */
    .page-loader {
      position: fixed; inset: 0; z-index: 99999;
      background: var(--bg-body);
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 30px;
      transition: opacity 0.6s ease, visibility 0.6s ease;
    }
    .page-loader.is-hidden { opacity: 0; visibility: hidden; pointer-events: none; }

    .page-loader-logo-wrap {
      position: relative;
      width: 150px; height: 150px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
    }
    .page-loader-logo-wrap::before {
      content: ''; position: absolute; inset: -14px; border-radius: 50%;
      background: conic-gradient(from 0deg, var(--teal-accent), var(--gold-accent), var(--red-accent), var(--teal-accent));
      filter: blur(20px); opacity: 0.55;
      animation: loaderSpin 3s linear infinite;
    }
    .page-loader-logo-wrap::after {
      content: ''; position: absolute; inset: -5px; border-radius: 50%; padding: 5px;
      background: conic-gradient(from 0deg, var(--teal-accent), var(--gold-accent), var(--red-accent), var(--teal-accent));
      -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor; mask-composite: exclude;
      animation: loaderSpin 3s linear infinite;
    }
    @keyframes loaderSpin { to { transform: rotate(360deg); } }

    .page-loader-logo {
      position: relative; z-index: 1;
      width: 128px; height: 128px; border-radius: 50%;
      object-fit: cover; background: #ffffff;
      box-shadow: 0 0 40px rgba(15, 23, 42, 0.18);
      animation: loaderPulse 2.4s ease-in-out infinite;
    }
    @keyframes loaderPulse {
      0%, 100% { transform: scale(1); }
      50%      { transform: scale(1.04); }
    }
    .page-loader-text {
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800; font-size: 20px;
      color: var(--text-main); letter-spacing: 0.4px;
      display: flex; align-items: center;
    }
    .page-loader-dots span {
      display: inline-block;
      color: var(--red-accent); font-weight: 800;
      animation: loaderDotBlink 1.4s infinite;
    }
    .page-loader-dots span:nth-child(2) { animation-delay: 0.2s; }
    .page-loader-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes loaderDotBlink {
      0%, 80%, 100% { opacity: 0.15; }
      40% { opacity: 1; }
    }
    body.is-loading { overflow: hidden; }
    @media (max-width: 480px) {
      .page-loader-logo-wrap { width: 118px; height: 118px; }
      .page-loader-logo { width: 100px; height: 100px; }
      .page-loader-text { font-size: 17px; }
    }
  </style>
</head>
<body class="is-loading">

  <div class="page-loader" id="pageLoader">
    <div class="page-loader-logo-wrap">
      <img src="assets/images/images.png" alt="Lungsod ng Santiago Logo" class="page-loader-logo"
           onerror="this.onerror=null; this.style.display='none';">
    </div>
    <div class="page-loader-text">Loading System<span class="page-loader-dots"><span>.</span><span>.</span><span>.</span></span></div>
  </div>

  <!-- Flat light background (dashboard match) -->
  <div class="bg-canvas">
    <div class="bg-gradient"></div>
  </div>

  <!-- Header (red gradient like dashboard top bar) -->
  <header class="site-header" id="header">
    <div class="container">
      <a class="brand" href="index.php">
        <span class="logo">
          <img src="assets/images/images.png" alt="Municipal Logo" loading="lazy"
               onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<span style=&quot;font-weight:800;font-size:16px;color:#dc2626;&quot;>RL</span>';">
        </span>
        <span>
          <div class="name">RENTRACK <span class="gold">LGU</span></div>
          <div class="tagline">Registration · Tracking · Compliance</div>
        </span>
      </a>

      <nav class="nav-links" id="navLinks">
        <a href="#features">Features</a>
        <a href="#how">How It Works</a>
        <a href="#portals">Portals</a>
        <a href="#contact">Contact</a>
        <a href="choose_portal.php" class="nav-link-login">Log In</a>
      </nav>

      <div class="nav-actions">
        <a href="choose_portal.php" class="btn btn-white">Get Started</a>
        <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="navLinks">
          <i class="fa-solid fa-bars" id="mobileToggleIcon"></i>
        </button>
      </div>
    </div>
  </header>

  <!-- Hero -->
  <section class="hero" id="hero">
    <div class="container">
      <div class="hero-visual">
        <div class="hero-glow"></div>

        <div class="hero-caption">
          <div class="hero-badge">
            <span class="live-dot" aria-hidden="true"></span>
            <i class="fa-solid fa-landmark-dome"></i> RENTRACK LGU v2.1
          </div>
          <p class="hero-tagline">
            A web-based unified system for <span class="highlight">registration, tracking and compliance</span> of all rental types in the LGU.
          </p>
        </div>

        <div class="hero-mock">
          <div class="hero-photo">
            <video src="assets/videos/city-hall-entrance.mp4" autoplay loop muted playsinline
                   poster="assets/images/city-hall-entrance.jpg"
                   aria-label="Balay Nan Santiago, Lungsod ng Santiago"></video>
          </div>
          <div class="hero-mock-badge" aria-hidden="true"><i class="fa-solid fa-landmark"></i></div>
          <div class="hero-mock-dot" aria-hidden="true"></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Stats -->
  <section id="stats">
    <div class="container">
      <div class="stats-grid">
        <div class="stat-card reveal">
          <div class="number">4</div>
          <div class="label">Role-Based Portals</div>
        </div>
        <div class="stat-card reveal">
          <div class="number">100%</div>
          <div class="label">Digital Registration</div>
        </div>
        <div class="stat-card reveal">
          <div class="number">24/7</div>
          <div class="label">Record Availability</div>
        </div>
        <div class="stat-card reveal">
          <div class="number">1</div>
          <div class="label">Unified Compliance Ledger</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features -->
  <section id="features">
    <div class="container">
      <div class="section-head reveal">
        <div class="section-eyebrow"><i class="fa-solid fa-layer-group"></i> Platform</div>
        <h2>Everything the LGU Needs, <span class="gradient-text">One System</span></h2>
        <p>From first application to yearly renewal, Rentrack keeps registration, monitoring, and enforcement in a single source of truth.</p>
      </div>

      <div class="features-grid">
        <div class="feature-card reveal">
          <div class="icon"><i class="fa-solid fa-file-signature"></i></div>
          <h3>Unified Registration</h3>
          <p>Property owners submit and update rental registrations online for residential, commercial, and mixed-use spaces.</p>
        </div>
        <div class="feature-card reveal">
          <div class="icon tone-teal"><i class="fa-solid fa-location-crosshairs"></i></div>
          <h3>Real-Time Tracking</h3>
          <p>Every unit's status, payment history, and inspection record updates live for instant visibility.</p>
        </div>
        <div class="feature-card reveal">
          <div class="icon tone-emerald"><i class="fa-solid fa-clipboard-check"></i></div>
          <h3>Compliance Monitoring</h3>
          <p>Automatic flags for expiring permits, unpaid dues, or overdue inspections keep everyone ahead.</p>
        </div>
        <div class="feature-card reveal">
          <div class="icon tone-gold"><i class="fa-solid fa-magnifying-glass-location"></i></div>
          <h3>Inspection Scheduling</h3>
          <p>Inspectors get organized route lists and digital report forms, replacing paper checklists.</p>
        </div>
        <div class="feature-card reveal">
          <div class="icon tone-violet"><i class="fa-solid fa-house-chimney-user"></i></div>
          <h3>Owner Self-Service</h3>
          <p>Owners manage accounts, documents, and payment status without visiting the LGU office.</p>
        </div>
        <div class="feature-card reveal">
          <div class="icon"><i class="fa-solid fa-user-shield"></i></div>
          <h3>Role-Based Access</h3>
          <p>Admin, staff, inspector, and owner each get a dedicated portal scoped to their role.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- How It Works -->
  <section id="how">
    <div class="container">
      <div class="section-head reveal">
        <div class="section-eyebrow"><i class="fa-solid fa-route"></i> Process</div>
        <h2>From Application to <span class="gradient-text">Renewal</span></h2>
        <p>A straightforward path that keeps every rental type accounted for and compliant, year after year.</p>
      </div>

      <div class="steps-grid reveal">
        <div class="step">
          <div class="num">1</div>
          <h3>Register</h3>
          <p>Owner submits property and rental details through the Owner Portal.</p>
        </div>
        <div class="step">
          <div class="num">2</div>
          <h3>Verify</h3>
          <p>LGU staff reviews documents and approves the registration record.</p>
        </div>
        <div class="step">
          <div class="num">3</div>
          <h3>Inspect &amp; Track</h3>
          <p>Inspectors log visits; the system tracks status and dues in real time.</p>
        </div>
        <div class="step">
          <div class="num">4</div>
          <h3>Renew</h3>
          <p>Reminders prompt timely renewal, keeping compliance continuous.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Portals -->
  <section id="portals">
    <div class="container">
      <div class="section-head reveal">
        <div class="section-eyebrow"><i class="fa-solid fa-arrow-right-to-bracket"></i> Access</div>
        <h2>Choose Your <span class="gradient-text">Portal</span></h2>
        <p>Every role has a dedicated portal with exactly the tools needed to get the job done.</p>
      </div>

      <div class="portals-scroll reveal">
        <div class="portal-preview">
          <i class="fa-solid fa-user-shield" style="font-size: 24px; color: #dc2626; margin-bottom: 12px;"></i>
          <div class="title">Admin</div>
          <div class="desc">Full system oversight &amp; control</div>
        </div>
        <div class="portal-preview">
          <i class="fa-solid fa-users-gear" style="font-size: 24px; color: #059669; margin-bottom: 12px;"></i>
          <div class="title">Staff</div>
          <div class="desc">Team dashboard &amp; daily tools</div>
        </div>
        <div class="portal-preview">
          <i class="fa-solid fa-magnifying-glass" style="font-size: 24px; color: #d97706; margin-bottom: 12px;"></i>
          <div class="title">Inspector</div>
          <div class="desc">Field inspections &amp; reports</div>
        </div>
        <div class="portal-preview">
          <i class="fa-solid fa-house-chimney-user" style="font-size: 24px; color: #2563eb; margin-bottom: 12px;"></i>
          <div class="title">Owner</div>
          <div class="desc">Rental accounts &amp; documents</div>
        </div>
      </div>

      <div style="text-align:center; margin-top:28px;" class="reveal">
        <a href="choose_portal.php" class="btn btn-primary">
          Access Your Portal <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>
  </section>

  <!-- CTA Banner -->
  <section>
    <div class="container reveal">
      <div class="cta-banner">
        <h2>Ready to Bring Your LGU Online?</h2>
        <p>Choose your portal and start registering, tracking, or managing rental compliance today.</p>
        <div style="display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;">
          <a href="choose_portal.php" class="btn btn-white">
            Get Started <i class="fa-solid fa-arrow-right"></i>
          </a>
          <a href="#features" class="btn btn-ghost-white">Learn More</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer (light like dashboard) -->
  <footer class="site-footer" id="contact">
    <div class="container">
      <div class="footer-grid">
        <div>
          <a class="brand" href="index.php" style="margin-bottom: 16px; display: flex;">
            <span class="logo">
              <img src="assets/images/images.png" alt="Municipal Logo" loading="lazy"
                   onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<span style=&quot;font-weight:800;font-size:16px;color:#dc2626;&quot;>RL</span>';">
            </span>
            <span>
              <div class="name">RENTRACK <span class="gold">LGU</span></div>
              <div class="tagline">Unified Rental System</div>
            </span>
          </a>
          <p style="line-height: 1.7; max-width: 280px;">
            A web-based unified system for registration, tracking and compliance of all rental types in the LGU.
          </p>
        </div>

        <div>
          <h4>Portals</h4>
          <p><a href="login.php?portal=admin">Admin Portal</a></p>
          <p><a href="login.php?portal=staff">Staff Portal</a></p>
          <p><a href="login.php?portal=inspector">Inspector Portal</a></p>
          <p><a href="login.php?portal=owner">Owner Portal</a></p>
        </div>

        <div>
          <h4>System</h4>
          <p><a href="#features">Features</a></p>
          <p><a href="#how">How It Works</a></p>
          <p><a href="#portals">Portals</a></p>
          <p><a href="choose_portal.php">Log In</a></p>
        </div>

        <div>
          <h4>Contact LGU</h4>
          <p><i class="fa-solid fa-location-dot"></i> Municipal Hall, City of Santiago, Philippines</p>
          <p><i class="fa-solid fa-phone"></i> (078) 123-4567</p>
          <p><i class="fa-solid fa-envelope"></i> rentrack@lgu.gov.ph</p>
        </div>
      </div>

      <div class="footer-bottom">
        <div>
          &copy; <?php echo date('Y'); ?> <span style="color: var(--red-accent); font-weight: 800;">RENTRACK LGU</span> &middot; Unified Rental Registration, Tracking and Compliance System
        </div>
        <div style="display: flex; gap: 18px;">
          <a href="privacy.php">Privacy</a>
          <a href="terms.php">Terms</a>
          <a href="help.php">Help</a>
        </div>
      </div>
    </div>
  </footer>

  <button class="back-top" id="backTop" aria-label="Back to top">
    <i class="fa-solid fa-arrow-up"></i>
  </button>

  <audio id="clickSound" src="assets/sounds/click.mp3" preload="auto"></audio>

  <script>
    function respectDataSaver() {
      const heroVideo = document.querySelector('.hero-photo video');
      if (!heroVideo) return;
      const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      const isSlow = conn && (conn.saveData || /2g/.test(conn.effectiveType || ''));
      if (isSlow) {
        heroVideo.pause();
        heroVideo.removeAttribute('autoplay');
        heroVideo.preload = 'none';
      }
    }
    window.addEventListener('load', () => { respectDataSaver(); });
  </script>

  <script>
    (function() {
      const clickAudio = document.getElementById('clickSound');
      function playAudioClick() {
        if (clickAudio) {
          clickAudio.currentTime = 0;
          clickAudio.play().catch(e => console.log("Audio play prevented:", e));
        }
      }

      const interactiveElements = document.querySelectorAll('.btn, .portal-preview, .nav-links a');
      interactiveElements.forEach(el => {
        el.addEventListener('click', () => { playAudioClick(); });
      });

      const toggle = document.getElementById('mobileToggle');
      const nav = document.getElementById('navLinks');
      const toggleIcon = document.getElementById('mobileToggleIcon');

      function setNavOpen(isOpen) {
        nav.classList.toggle('open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        if (toggleIcon) {
          toggleIcon.classList.toggle('fa-bars', !isOpen);
          toggleIcon.classList.toggle('fa-xmark', isOpen);
        }
      }

      toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        playAudioClick();
        setNavOpen(!nav.classList.contains('open'));
      });

      nav.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setNavOpen(false));
      });

      document.addEventListener('click', (e) => {
        if (nav.classList.contains('open') && !nav.contains(e.target) && !toggle.contains(e.target)) {
          setNavOpen(false);
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setNavOpen(false);
      });

      window.addEventListener('resize', () => {
        if (window.innerWidth > 900) setNavOpen(false);
      });

      const revealEls = document.querySelectorAll('.reveal');
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) entry.target.classList.add('visible');
        });
      }, { threshold: 0.1 });
      revealEls.forEach(el => observer.observe(el));

      const backTop = document.getElementById('backTop');
      window.addEventListener('scroll', () => {
        if (window.scrollY > 400) backTop.classList.add('show');
        else backTop.classList.remove('show');
      });
      backTop.addEventListener('click', () => {
        playAudioClick();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });

      const header = document.getElementById('header');
      window.addEventListener('scroll', () => {
        if (window.scrollY > 30) header.classList.add('scrolled');
        else header.classList.remove('scrolled');
      });
    })();
  </script>

  <script>
    (function() {
      const loader = document.getElementById('pageLoader');
      if (!loader) return;
      const MIN_VISIBLE_MS = 1200;
      const shownAt = Date.now();
      function hideLoader() {
        const elapsed = Date.now() - shownAt;
        const wait = Math.max(MIN_VISIBLE_MS - elapsed, 0);
        setTimeout(() => {
          loader.classList.add('is-hidden');
          document.body.classList.remove('is-loading');
          setTimeout(() => loader.remove(), 650);
        }, wait);
      }
      if (document.readyState === 'complete') hideLoader();
      else window.addEventListener('load', hideLoader);
      setTimeout(hideLoader, 6000);
    })();
  </script>
</body>
</html>