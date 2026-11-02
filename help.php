<?php
// help.php — RENTRACK LGU Help & Support (Dashboard-matched light theme)
$page_title = "Help & Support - RENTRACK LGU";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes,viewport-fit=cover" />
  <title><?php echo $page_title; ?></title>
  <meta name="description" content="Guides, FAQs and contact information for using RENTRACK LGU." />

  <meta name="theme-color" content="#dc2626" />
  <meta name="color-scheme" content="light" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="RENTRACK LGU" />
  <link rel="apple-touch-icon" href="assets/images/images.png" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <style>
    /* ============================================
       DESIGN SYSTEM — matches index.php
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

    .wrap {
      width: 100%; max-width: var(--max-width); margin: 0 auto;
      padding: 0 var(--container-pad); position: relative; z-index: 10;
    }

    /* ============================================
       LIGHT BG — same as index
    ============================================ */
    .bg-canvas {
      position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
    }
    .bg-gradient {
      position: absolute; inset: 0;
      background: var(--bg-body);
    }
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
    }

    /* ============================================
       HEADER — RED GRADIENT (matches index)
    ============================================ */
    .site-header {
      position: sticky; top: 0; left: 0; right: 0; z-index: 100;
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      border-bottom: 1px solid rgba(0, 0, 0, 0.1);
      box-shadow: 0 4px 15px rgba(220, 38, 38, 0.2);
      transition: var(--transition);
      padding-top: var(--safe-top);
    }
    .site-header.scrolled {
      box-shadow: 0 10px 30px rgba(153, 27, 27, 0.35);
    }
    .site-header .wrap {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; height: var(--header-h);
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
        max-height: calc(100dvh - var(--header-h) - var(--safe-top));
        overflow-y: auto; -webkit-overflow-scrolling: touch;
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
       PAGE HERO
    ============================================ */
    .page-hero { padding: calc(var(--header-h) + 40px) 0 24px; position: relative; z-index: 2; }
    .breadcrumb {
      font-size: 12.5px; color: var(--text-muted);
      margin-bottom: 16px; font-weight: 600;
    }
    .breadcrumb a { color: var(--red-accent); font-weight: 700; }
    .breadcrumb a:hover { text-decoration: underline; }
    .breadcrumb i { font-size: 9px; margin: 0 8px; opacity: 0.6; }

    .kicker {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.28);
      color: var(--red-deep);
      padding: 7px 18px 7px 14px;
      border-radius: 999px;
      font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.4px;
      margin-bottom: 18px;
      box-shadow: 0 6px 18px -8px rgba(220, 38, 38, 0.35);
    }

    .page-hero h1 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 40px; font-weight: 800;
      color: var(--text-main); margin-bottom: 12px;
      letter-spacing: -0.5px;
    }
    .page-hero p {
      font-size: 15px; color: var(--text-sub);
      max-width: 620px; line-height: 1.7;
    }

    /* ============================================
       CONTENT CARD
    ============================================ */
    .content-section { padding: 30px 0 80px; position: relative; z-index: 2; }

    .content-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 28px;
      padding: 44px 40px;
      box-shadow: var(--shadow-soft);
      position: relative;
    }

    .content-card .block { margin-bottom: 40px; }
    .content-card .block:last-child { margin-bottom: 0; }

    .content-card h2 {
      display: flex; align-items: center; gap: 12px;
      font-family: 'Space Grotesk', sans-serif;
      font-size: 19px; font-weight: 800;
      color: var(--text-main);
      padding-bottom: 12px; margin-bottom: 18px;
      border-bottom: 1px solid var(--border-color);
    }
    .content-card h2 .num {
      width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.28);
      color: var(--red-accent);
      font-family: 'Space Grotesk', sans-serif;
      font-size: 13px; font-weight: 800;
    }

    .content-card p {
      font-size: 14px; line-height: 1.8;
      color: var(--text-sub); margin-bottom: 12px;
    }

    .content-card a.link {
      color: var(--red-accent); font-weight: 700;
      text-decoration: underline; text-underline-offset: 3px;
    }
    .content-card a.link:hover { color: var(--red-deep); }

    /* ============================================
       FAQ ACCORDION (light theme)
    ============================================ */
    .faq-item {
      background: var(--bg-card-alt);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      margin-bottom: 14px;
      overflow: hidden;
      transition: var(--transition);
    }
    .faq-item:last-child { margin-bottom: 0; }
    .faq-item[open] {
      border-color: rgba(220, 38, 38, 0.35);
      background: var(--bg-card);
      box-shadow: 0 4px 14px -6px rgba(220, 38, 38, 0.15);
    }
    .faq-item summary {
      list-style: none; cursor: pointer; padding: 20px 24px;
      display: flex; align-items: center; justify-content: space-between; gap: 14px;
      font-weight: 700; font-size: 15px; color: var(--text-main);
      transition: var(--transition);
    }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary:hover { color: var(--red-accent); }
    .faq-item summary .q { display: flex; align-items: center; gap: 12px; }
    .faq-item summary .q i {
      color: var(--red-accent); font-size: 15px; flex-shrink: 0;
      width: 32px; height: 32px; border-radius: 8px;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.2);
      display: inline-flex; align-items: center; justify-content: center;
    }
    .faq-item summary .chev {
      color: var(--red-accent);
      transition: transform 0.3s ease;
      font-size: 13px;
      flex-shrink: 0;
    }
    .faq-item[open] summary .chev { transform: rotate(180deg); }
    .faq-item .faq-body {
      padding: 0 24px 22px 68px;
      font-size: 14px; color: var(--text-sub); line-height: 1.8;
      border-top: 1px dashed var(--border-color);
      margin-top: -4px;
      padding-top: 16px;
    }
    .faq-item .faq-body a {
      color: var(--red-accent); font-weight: 700;
      text-decoration: underline; text-underline-offset: 3px;
    }
    .faq-item .faq-body a:hover { color: var(--red-deep); }

    /* ============================================
       CONTACT GRID (light theme)
    ============================================ */
    .contact-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
      margin-top: 12px;
    }
    .contact-card {
      background: var(--bg-card-alt);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 24px 20px;
      text-align: center;
      text-decoration: none;
      transition: var(--transition);
      display: block;
    }
    .contact-card:hover {
      transform: translateY(-4px);
      border-color: rgba(220, 38, 38, 0.35);
      background: var(--bg-card);
      box-shadow: var(--shadow-lift);
    }
    .contact-card .icon {
      width: 46px; height: 46px; border-radius: 12px; margin: 0 auto 14px;
      display: flex; align-items: center; justify-content: center;
      background: var(--red-soft);
      border: 1px solid rgba(220, 38, 38, 0.22);
      color: var(--red-accent); font-size: 18px;
      transition: var(--transition);
    }
    .contact-card:hover .icon {
      transform: scale(1.08) rotate(-4deg);
    }
    .contact-card .lbl {
      font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
      color: var(--text-muted); margin-bottom: 6px;
      font-weight: 700;
    }
    .contact-card .val {
      font-size: 13px; font-weight: 700;
      color: var(--text-main);
      word-break: break-word;
    }

    @media (max-width: 640px) {
      .contact-grid { grid-template-columns: 1fr; }
      .content-card { padding: 30px 22px; border-radius: 22px; }
      .page-hero h1 { font-size: 30px; }
      .page-hero p { font-size: 14px; }
      .content-card h2 { font-size: 16.5px; }
      .faq-item summary { padding: 16px 18px; font-size: 14px; }
      .faq-item .faq-body { padding: 14px 18px 18px 18px; }
    }

    /* ============================================
       FOOTER (light, matches index)
    ============================================ */
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
    .back-top:hover {
      background: linear-gradient(135deg, var(--red-hover), var(--red-accent));
      transform: translateY(-2px);
    }

    @media (max-width: 900px) {
      .footer-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 640px) {
      :root { --header-h: 66px; --container-pad: 16px; }
      .brand .logo { width: 38px; height: 38px; border-radius: 10px; }
      .brand .name { font-size: 15px; }
      .brand .tagline { font-size: 9px; letter-spacing: 0.5px; }
      .mobile-toggle { width: 40px; height: 40px; font-size: 17px; }

      .footer-grid { grid-template-columns: 1fr; gap: 28px; }
      .footer-bottom { flex-direction: column; gap: 12px; text-align: center; }

      .back-top { width: 42px; height: 42px; font-size: 13px; }
    }
    @media (max-width: 380px) {
      .brand { gap: 8px; }
      .brand .tagline { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
    }

    /* ============================================
       PAGE LOADER — LIGHT THEME (matches index)
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

  <div class="bg-canvas">
    <div class="bg-gradient"></div>
  </div>

  <!-- Header -->
  <header class="site-header" id="header">
    <div class="wrap">
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
        <a href="index.php#features">Features</a>
        <a href="index.php#how">How It Works</a>
        <a href="index.php#portals">Portals</a>
        <a href="index.php#contact">Contact</a>
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

  <main>
    <!-- Page Hero -->
    <section class="page-hero">
      <div class="wrap">
        <div class="breadcrumb">
          <a href="index.php">Home</a>
          <i class="fa-solid fa-chevron-right"></i>
          Help &amp; Support
        </div>
        <span class="kicker"><i class="fa-solid fa-circle-question"></i> Support</span>
        <h1>Help &amp; Support</h1>
        <p>Guides, FAQs and contact information for using RENTRACK LGU.</p>
      </div>
    </section>

    <!-- Content Card -->
    <section class="content-section">
      <div class="wrap">
        <div class="content-card">

          <div class="block">
            <h2><span class="num">1</span> Frequently Asked Questions</h2>

            <details class="faq-item" open>
              <summary>
                <span class="q"><i class="fa-solid fa-file-signature"></i> How do I register a rental?</span>
                <i class="fa-solid fa-chevron-down chev"></i>
              </summary>
              <div class="faq-body">
                Click "Register a Rental" on the top-right (or the Register button on the homepage) and complete the form with required documents.
              </div>
            </details>

            <details class="faq-item">
              <summary>
                <span class="q"><i class="fa-solid fa-key"></i> I forgot my password — what do I do?</span>
                <i class="fa-solid fa-chevron-down chev"></i>
              </summary>
              <div class="faq-body">
                Use the "Forgot password" link on the login page or contact support at <a href="mailto:info@rentracklgu.gov.ph">info@rentracklgu.gov.ph</a>.
              </div>
            </details>

            <details class="faq-item">
              <summary>
                <span class="q"><i class="fa-solid fa-eye"></i> Who can view rental records?</span>
                <i class="fa-solid fa-chevron-down chev"></i>
              </summary>
              <div class="faq-body">
                Access depends on user role: LGU staff and authorized inspectors can view records for their jurisdiction. Owners can see and manage their own rental records.
              </div>
            </details>
          </div>

          <div class="block">
            <h2><span class="num">2</span> Contact Support</h2>
            <p>If you need further assistance, reach out to:</p>
            <div class="contact-grid">
              <a class="contact-card" href="mailto:info@rentracklgu.gov.ph">
                <div class="icon"><i class="fa-solid fa-envelope"></i></div>
                <div class="lbl">Email</div>
                <div class="val">info@rentracklgu.gov.ph</div>
              </a>
              <a class="contact-card" href="tel:+0000000000">
                <div class="icon"><i class="fa-solid fa-phone"></i></div>
                <div class="lbl">Phone</div>
                <div class="val">(000) 000-0000</div>
              </a>
              <div class="contact-card">
                <div class="icon"><i class="fa-solid fa-building-columns"></i></div>
                <div class="lbl">Office</div>
                <div class="val">Local Government Unit</div>
              </div>
            </div>
          </div>

          <div class="block">
            <h2><span class="num">3</span> Send Feedback</h2>
            <p>
              We welcome feedback to improve the Service. Please email suggestions to
              <a class="link" href="mailto:info@rentracklgu.gov.ph">info@rentracklgu.gov.ph</a>.
            </p>
          </div>

        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="site-footer" id="contact">
    <div class="wrap">
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
          <p><a href="index.php#features">Features</a></p>
          <p><a href="index.php#how">How It Works</a></p>
          <p><a href="index.php#portals">Portals</a></p>
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
          <a href="help.php" style="color: var(--red-accent); font-weight: 700;">Help</a>
        </div>
      </div>
    </div>
  </footer>

  <button class="back-top" id="backTop" aria-label="Back to top">
    <i class="fa-solid fa-arrow-up"></i>
  </button>

  <script>
    (function() {
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

      const backTop = document.getElementById('backTop');
      window.addEventListener('scroll', () => {
        if (window.scrollY > 400) backTop.classList.add('show');
        else backTop.classList.remove('show');
      });
      backTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

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