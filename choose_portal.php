<?php
// choose_portal.php — RENTRACK LGU Portal Selector (Dashboard-Matched Light Theme with RED Header/Footer)
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1.5,user-scalable=yes" />
  <meta name="theme-color" content="#dc2626">
  <title>Choose Portal · RENTRACK LGU</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      -webkit-tap-highlight-color: transparent;
    }

    :root {
      /* Dashboard-matched palette */
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
      --text-main: #0f172a;
      --text-sub: #334155;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --border-strong: #cbd5e1;
      --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
      --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
      --panel-radius: 20px;
      --tile-radius: 16px;
      --max-width: 820px;
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
      color: var(--text-main);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      position: relative;
      overflow-x: hidden;
      overflow-y: auto;
      touch-action: manipulation;
    }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    /* ===== BACKGROUND (subtle light pattern) ===== */
    .bg-canvas {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      overflow: hidden;
    }

    .bg-gradient {
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse at 15% 20%, rgba(220, 38, 38, 0.04) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 80%, rgba(8, 145, 178, 0.04) 0%, transparent 55%),
        linear-gradient(160deg, #f8fafc 0%, #f1f5f9 60%, #eef2f7 100%);
    }

    .orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(90px);
      opacity: 0.35;
      animation: orbFloat 20s ease-in-out infinite alternate;
    }

    .orb-1 { width: 420px; height: 420px; background: rgba(220, 38, 38, 0.10); top: -120px; left: -100px; animation-duration: 24s; }
    .orb-2 { width: 360px; height: 360px; background: rgba(8, 145, 178, 0.08); bottom: -100px; right: -80px; animation-duration: 20s; animation-delay: 4s; }
    .orb-3 { width: 280px; height: 280px; background: rgba(217, 119, 6, 0.06); top: 40%; right: 8%; animation-duration: 22s; animation-delay: 2s; }
    .orb-4 { width: 320px; height: 320px; background: rgba(220, 38, 38, 0.06); bottom: 18%; left: 8%; animation-duration: 26s; animation-delay: 6s; }

    @keyframes orbFloat {
      0% { transform: translate(0, 0) scale(1); }
      25% { transform: translate(70px, -50px) scale(1.08); }
      50% { transform: translate(-35px, 70px) scale(0.92); }
      75% { transform: translate(50px, 35px) scale(1.04); }
      100% { transform: translate(-50px, -35px) scale(0.96); }
    }

    .particles {
      position: absolute;
      inset: 0;
      overflow: hidden;
    }

    .particle {
      position: absolute;
      width: 3px;
      height: 3px;
      background: rgba(220, 38, 38, 0.15);
      border-radius: 50%;
      animation: particleFloat linear infinite;
    }

    .particle:nth-child(1) { left: 10%; animation-duration: 18s; animation-delay: 0s; }
    .particle:nth-child(2) { left: 22%; animation-duration: 22s; animation-delay: 2s; width: 4px; height: 4px; }
    .particle:nth-child(3) { left: 34%; animation-duration: 20s; animation-delay: 4s; width: 2px; height: 2px; }
    .particle:nth-child(4) { left: 46%; animation-duration: 24s; animation-delay: 1s; }
    .particle:nth-child(5) { left: 58%; animation-duration: 19s; animation-delay: 3s; }
    .particle:nth-child(6) { left: 70%; animation-duration: 23s; animation-delay: 5s; width: 4px; height: 4px; }
    .particle:nth-child(7) { left: 82%; animation-duration: 21s; animation-delay: 2s; width: 2px; height: 2px; }
    .particle:nth-child(8) { left: 90%; animation-duration: 20s; animation-delay: 4s; }

    @keyframes particleFloat {
      0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
      10% { opacity: 0.6; }
      90% { opacity: 0.6; }
      100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
    }

    .grid-overlay {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      background-image: 
        linear-gradient(rgba(15, 23, 42, 0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(15, 23, 42, 0.025) 1px, transparent 1px);
      background-size: 50px 50px;
      animation: gridPulse 8s ease-in-out infinite alternate;
    }

    @keyframes gridPulse {
      0% { opacity: 0.5; }
      100% { opacity: 1; }
    }

    /* ===== NAVIGATION (RED HEADER) ===== */
    .navbar {
      position: relative;
      width: 100%;
      padding: 12px 30px;
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      border-bottom: 1px solid rgba(0,0,0,0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 100;
      animation: navSlideDown 0.8s ease-out;
      flex-shrink: 0;
      box-shadow: 0 4px 15px rgba(220, 38, 38, 0.2);
    }

    @keyframes navSlideDown {
      0% { transform: translateY(-100%); opacity: 0; }
      100% { transform: translateY(0); opacity: 1; }
    }

    .navbar-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800;
      font-size: 15px;
      letter-spacing: 0.3px;
      color: #ffffff;
    }

    .navbar-brand i {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      background: #ffffff;
      color: var(--red-accent);
      font-size: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .navbar-brand span {
      color: #ffffff;
    }

    .navbar-links {
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .navbar-links a {
      color: rgba(255, 255, 255, 0.9);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: var(--transition);
      cursor: pointer;
      position: relative;
    }

    .navbar-links a:hover {
      color: #ffffff;
    }

    .navbar-links .btn-help {
      background: rgba(255, 255, 255, 0.15);
      padding: 8px 16px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.3);
      font-size: 12px;
      font-weight: 700;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #ffffff;
    }

    .navbar-links .btn-help i {
      color: #ffffff;
    }

    .navbar-links .btn-help:hover {
      background: #ffffff;
      color: var(--red-accent);
      border-color: #ffffff;
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    }
    
    .navbar-links .btn-help:hover i {
        color: var(--red-accent);
    }

    /* ===== MAIN CONTENT ===== */
    main {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 20px;
      position: relative;
      z-index: 1;
    }

    .panel {
      width: 100%;
      max-width: var(--max-width);
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--panel-radius);
      box-shadow:
        0 20px 45px -12px rgba(15, 23, 42, 0.10),
        0 4px 12px -4px rgba(15, 23, 42, 0.05);
      padding: 20px 26px 22px;
      text-align: center;
      position: relative;
      overflow: hidden;
      animation: panelIn 0.7s ease-out 0.15s both;
    }

    @keyframes panelIn {
      0% { opacity: 0; transform: translateY(24px) scale(0.97); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    .panel::before {
      content: '';
      position: absolute;
      width: 320px;
      height: 320px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(220, 38, 38, 0.05), transparent 70%);
      top: -170px;
      right: -120px;
      pointer-events: none;
    }

    .panel::after {
      content: '';
      position: absolute;
      width: 280px;
      height: 280px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(8, 145, 178, 0.04), transparent 70%);
      bottom: -150px;
      left: -100px;
      pointer-events: none;
    }

    /* Logo */
    .logo-wrapper {
      position: relative;
      display: inline-block;
      margin: 0 auto 6px;
      text-align: center;
      width: 100%;
    }

    .logo-circle {
      width: 88px;
      height: 88px;
      border-radius: 50%;
      background: #ffffff;
      border: 2px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto;
      box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
      transition: var(--transition);
      position: relative;
      animation: logoFloat 4s ease-in-out infinite;
    }

    @keyframes logoFloat {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-3px); }
    }

    .logo-circle:hover {
      transform: scale(1.04) translateY(-2px);
      border-color: var(--red-accent);
      box-shadow: 0 8px 24px rgba(220, 38, 38, 0.15);
    }

    .logo-circle img {
      width: 68px;
      height: 68px;
      object-fit: cover;
      border-radius: 50%;
      display: block;
    }

    .logo-glow {
      position: absolute;
      inset: -8px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(220, 38, 38, 0.06), transparent 70%);
      z-index: -1;
      animation: glowPulse 3s ease-in-out infinite;
      pointer-events: none;
    }

    @keyframes glowPulse {
      0%, 100% { opacity: 0.5; transform: scale(1); }
      50% { opacity: 1; transform: scale(1.1); }
    }

    /* Heading */
    .heading-section {
      position: relative;
      z-index: 1;
    }

    h1 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 21px;
      font-weight: 800;
      letter-spacing: -0.4px;
      margin: 0 0 3px;
      color: var(--text-main);
    }

    h1 .highlight {
      background: linear-gradient(135deg, var(--red-accent), var(--red-deep));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .sub-headline {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--text-muted);
      margin: 0 0 4px;
      letter-spacing: 0.1px;
    }

    .divider-line {
      width: 36px;
      height: 3px;
      border-radius: 999px;
      background: linear-gradient(90deg, var(--red-accent), var(--red-deep));
      margin: 6px auto 10px;
      animation: dividerExpand 2s ease-in-out infinite;
    }

    @keyframes dividerExpand {
      0%, 100% { width: 36px; }
      50% { width: 56px; }
    }

    /* Selector Label */
    .selector-label {
      position: relative;
      z-index: 5;
      font-size: 10.5px;
      font-weight: 700;
      color: var(--text-sub);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 10px;
    }

    .selector-label i {
      margin-right: 6px;
      color: var(--red-accent);
    }

    /* ===== 3D WHEEL ===== */
    .wheel-container {
      position: relative;
      z-index: 1;
      width: 100%;
      height: 250px;
      perspective: 1100px;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 4px 0 12px;
    }

    .wheel {
      position: relative;
      width: 190px;
      height: 220px;
      transform-style: preserve-3d;
      transition: transform 0.8s cubic-bezier(0.2, 0.8, 0.25, 1);
      cursor: grab;
    }
    
    .wheel:active {
      cursor: grabbing;
    }

    .wheel-tile {
      position: absolute;
      width: 180px;
      height: 210px;
      left: 50%;
      top: 50%;
      margin-left: -90px;
      margin-top: -105px;
      border-radius: var(--tile-radius);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 16px 14px;
      text-decoration: none;
      color: var(--text-main);
      backface-visibility: hidden;
      transform-style: preserve-3d;
      background: #ffffff;
      border: 1px solid var(--border-color);
      box-shadow:
        0 8px 20px -6px rgba(15, 23, 42, 0.10),
        0 2px 6px -2px rgba(15, 23, 42, 0.05);
      transition: all 0.4s cubic-bezier(0.2, 0.8, 0.25, 1);
      cursor: pointer;
      transform: rotateY(calc(var(--index) * 90deg)) translateZ(240px);
    }

    .wheel-tile:hover {
      transform: rotateY(calc(var(--index) * 90deg)) translateZ(255px) scale(1.05);
      border-color: var(--red-accent);
      box-shadow:
        0 20px 40px -8px rgba(220, 38, 38, 0.20),
        0 6px 14px -4px rgba(15, 23, 42, 0.10);
      z-index: 10;
    }

    .wheel-tile .icon {
      width: 46px;
      height: 46px;
      border-radius: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      margin-bottom: 12px;
      background: rgba(220, 38, 38, 0.10);
      border: 1px solid rgba(220, 38, 38, 0.15);
      color: var(--red-accent);
      transition: var(--transition);
    }

    .wheel-tile:hover .icon {
      transform: scale(1.1) rotate(-5deg);
      background: var(--red-accent);
      border-color: var(--red-accent);
      color: #ffffff;
      box-shadow: 0 4px 15px rgba(220, 38, 38, 0.35);
    }

    .wheel-tile .title {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 19px;
      font-weight: 800;
      margin: 0 0 5px;
      letter-spacing: 0.2px;
      color: var(--text-main);
    }

    .wheel-tile .desc {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--text-muted);
      line-height: 1.35;
      margin: 0;
    }

    .wheel-tile::after {
      content: '';
      position: absolute;
      bottom: 14px;
      left: 38%;
      right: 38%;
      height: 3px;
      border-radius: 999px;
      background: var(--border-strong);
      opacity: 0.5;
      transition: var(--transition);
    }

    .wheel-tile:hover::after {
      background: var(--red-accent);
      left: 30%;
      right: 30%;
      opacity: 1;
    }

    .wheel-tile.active-card {
      border-color: var(--red-accent);
      box-shadow:
        0 16px 36px -8px rgba(220, 38, 38, 0.22),
        0 0 0 3px rgba(220, 38, 38, 0.08);
    }

    .wheel-tile.active-card .icon {
      background: var(--red-accent);
      border-color: var(--red-accent);
      color: #ffffff;
    }

    .wheel-tile.active-card::after {
      background: var(--red-accent);
      left: 30%;
      right: 30%;
      opacity: 1;
    }

    /* Controls */
    .wheel-controls {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
      position: relative;
      z-index: 2;
      margin-bottom: 6px;
    }

    .wheel-btn {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--bg-card-alt);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: var(--transition);
      font-size: 12px;
      box-shadow: var(--shadow-soft);
    }

    .wheel-btn:hover {
      background: var(--red-accent);
      border-color: var(--red-accent);
      color: #ffffff;
      transform: scale(1.08);
      box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
    }

    .wheel-btn:active {
      transform: scale(0.92);
    }

    .wheel-btn i {
      pointer-events: none;
    }

    .wheel-indicators {
      display: flex;
      gap: 6px;
    }

    .wheel-indicator {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--border-strong);
      transition: var(--transition);
      border: none;
      padding: 0;
      cursor: pointer;
    }

    .wheel-indicator.active {
      background: var(--red-accent);
      transform: scale(1.35);
      box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    }

    .wheel-indicator:hover {
      background: var(--red-hover);
    }

    /* Footnote */
    .footnote {
      margin-top: 8px;
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 500;
      animation: fadeInUp 1s ease-out 0.6s both;
    }

    @keyframes fadeInUp {
      0% { opacity: 0; transform: translateY(8px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    .footnote i {
      margin-right: 4px;
      color: var(--red-accent);
    }

    /* ===== FOOTER (RED FOOTER) ===== */
    .site-footer {
      background: linear-gradient(90deg, var(--red-deep), var(--red-accent) 55%, var(--red-deep));
      color: rgba(255, 255, 255, 0.9);
      padding: 14px 20px;
      text-align: center;
      font-size: 11.5px;
      border-top: 1px solid rgba(0,0,0,0.1);
      position: relative;
      z-index: 1;
      flex-shrink: 0;
      animation: fadeInUp 1s ease-out 0.8s both;
      font-weight: 500;
      box-shadow: 0 -4px 15px rgba(220, 38, 38, 0.15);
    }

    .site-footer .brand {
      font-weight: 800;
      color: #ffffff;
      margin: 0 4px;
      text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }

    .site-footer .separator {
      color: rgba(255, 255, 255, 0.4);
      margin: 0 6px;
    }

    /* ===== PORTAL SPLASH ===== */
    .portal-splash {
      position: fixed;
      inset: 0;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: rgba(248, 250, 252, 0.92);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.4s ease;
    }

    .portal-splash.show {
      opacity: 1;
      visibility: visible;
    }

    .timeclock {
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .timeclock.punch-shake {
      animation: clockShake 0.4s ease;
    }

    @keyframes clockShake {
      0%, 100% { transform: translateX(0) rotate(0deg); }
      20% { transform: translateX(-5px) rotate(-0.5deg); }
      40% { transform: translateX(5px) rotate(0.5deg); }
      60% { transform: translateX(-3px); }
      80% { transform: translateX(3px); }
    }

    .timeclock-body {
      position: relative;
      width: 240px;
      padding: 18px 16px 20px;
      background: #ffffff;
      border: 2px solid var(--border-color);
      border-radius: 16px 16px 8px 8px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18), 0 0 0 6px rgba(220, 38, 38, 0.06);
      animation: splashIn 0.6s ease-out;
    }

    @keyframes splashIn {
      0% { transform: scale(0.8) rotate(-5deg); opacity: 0; }
      100% { transform: scale(1) rotate(0deg); opacity: 1; }
    }

    .timeclock-brand {
      text-align: center;
      font-family: 'Space Grotesk', sans-serif;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      background: linear-gradient(90deg, var(--red-accent), var(--red-deep));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 8px;
    }

    .timeclock-display {
      text-align: center;
      font-family: 'Plus Jakarta Sans', ui-monospace, monospace;
      font-size: 20px;
      font-weight: 800;
      letter-spacing: 3px;
      color: #eafff1;
      background: #0f172a;
      border-radius: 8px;
      padding: 9px 6px;
      box-shadow: inset 0 2px 8px rgba(0,0,0,0.4);
      margin-bottom: 10px;
      border: 1px solid #1e293b;
    }

    .timeclock-slot {
      position: relative;
      height: 70px;
      background: var(--bg-card-alt);
      border: 1px solid var(--border-color);
      border-radius: 6px;
      box-shadow: inset 0 4px 12px rgba(15, 23, 42, 0.08);
      overflow: hidden;
      display: flex;
      align-items: flex-end;
      justify-content: center;
    }

    .timeclock-slot::before {
      content: '';
      position: absolute;
      top: 12px;
      left: 8%;
      right: 8%;
      height: 4px;
      background: #0f172a;
      border-radius: 999px;
      z-index: 2;
      opacity: 0.6;
    }

    .timeclock-card {
      position: relative;
      width: 82%;
      min-height: 120px;
      background: linear-gradient(180deg, #ffffff, #f8fafc);
      border: 1px solid var(--border-color);
      border-radius: 4px 4px 10px 10px;
      box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
      transform: translateY(80%);
      transition: transform 0.5s cubic-bezier(0.2, 0.8, 0.25, 1);
      padding: 12px 8px 8px;
      z-index: 1;
    }

    .timeclock-card.inserted {
      transform: translateY(22%);
    }

    .card-hole {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #0f172a;
      margin: 0 auto 6px;
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.4);
    }

    .card-portal {
      text-align: center;
      font-weight: 800;
      font-size: 11px;
      letter-spacing: 0.6px;
      color: var(--text-main);
      text-transform: uppercase;
      border-bottom: 1.5px dashed var(--border-strong);
      padding-bottom: 6px;
      margin-bottom: 6px;
    }

    .card-stamp {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      opacity: 0;
      transform: scale(2.6) rotate(-14deg);
      transition: all 0.2s cubic-bezier(0.3, 1.6, 0.5, 1);
    }

    .card-stamp.stamped {
      opacity: 1;
      transform: scale(1) rotate(-6deg);
    }

    .stamp-text {
      font-weight: 800;
      font-size: 9px;
      letter-spacing: 1.5px;
      color: var(--red-accent);
      border: 2px solid var(--red-accent);
      border-radius: 4px;
      padding: 2px 8px;
      text-transform: uppercase;
      background: var(--red-soft);
      box-shadow: 0 2px 6px rgba(220, 38, 38, 0.2);
    }

    .stamp-time {
      font-family: 'Plus Jakarta Sans', ui-monospace, monospace;
      font-weight: 700;
      font-size: 9px;
      color: var(--red-accent);
      opacity: 0.95;
    }

    .timeclock-base {
      width: 260px;
      height: 10px;
      background: linear-gradient(180deg, #cbd5e1, #e2e8f0);
      border-radius: 0 0 12px 12px;
      margin-top: -4px;
    }

    .timeclock-status {
      margin-top: 16px;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--text-main);
      min-height: 16px;
    }

    /* ===== HELP MODAL ===== */
    .help-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 9998;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s ease;
    }

    .help-modal-overlay.show {
      opacity: 1;
      visibility: visible;
    }

    .help-modal {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 20px;
      padding: 32px 36px;
      max-width: 450px;
      width: 90%;
      text-align: center;
      box-shadow: 0 30px 70px rgba(15, 23, 42, 0.2);
      transform: translateY(30px) scale(0.95);
      transition: transform 0.3s ease;
    }

    .help-modal-overlay.show .help-modal {
      transform: translateY(0) scale(1);
    }

    .help-modal > i {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: var(--red-soft);
      color: var(--red-accent);
      font-size: 24px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
    }

    .help-modal h2 {
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 800;
      font-size: 22px;
      margin-bottom: 10px;
      color: var(--text-main);
    }

    .help-modal p {
      color: var(--text-muted);
      font-size: 14px;
      line-height: 1.55;
      margin-bottom: 8px;
    }

    .help-modal .contact-email {
      font-weight: 700;
      color: var(--red-accent);
      font-size: 15px;
      margin: 10px 0 8px;
      display: block;
    }

    .help-modal .contact-email i {
      margin-right: 4px;
      font-size: 13px;
    }

    .help-modal .btn-close-modal {
      background: var(--red-accent);
      border: none;
      padding: 11px 32px;
      border-radius: 999px;
      color: #ffffff;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      margin-top: 16px;
      transition: var(--transition);
    }

    .help-modal .btn-close-modal:hover {
      background: var(--red-hover);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 640px) {
      .navbar { padding: 10px 16px; }
      .navbar-brand { font-size: 13px; gap: 8px; }
      .navbar-brand i { width: 28px; height: 28px; font-size: 12px; }
      .navbar-links .btn-help { padding: 6px 12px; font-size: 11px; }
      .panel { padding: 16px 16px 18px; border-radius: 16px; }
      h1 { font-size: 18px; }
      .sub-headline { font-size: 10.5px; }
      .logo-circle { width: 76px; height: 76px; }
      .logo-circle img { width: 58px; height: 58px; }
      .wheel-container { height: 230px; }
      .wheel-tile .title { font-size: 17px; }
      .wheel-tile .desc { font-size: 10.5px; }
      .site-footer { font-size: 10.5px; padding: 12px 14px; }
    }

    @media (max-width: 400px) {
      .navbar-links .btn-help span { display: none; }
      h1 { font-size: 16px; }
      .sub-headline { font-size: 10px; }
    }

    @media (prefers-reduced-motion: reduce) {
      * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }
  </style>
</head>
<body>

  <!-- ===== BACKGROUND ===== -->
  <div class="bg-canvas">
    <div class="bg-gradient"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="orb orb-4"></div>
    <div class="particles">
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
      <div class="particle"></div>
    </div>
    <div class="grid-overlay"></div>
  </div>

  <!-- ===== NAVIGATION ===== -->
  <nav class="navbar" role="navigation" aria-label="Main navigation">
    <div class="navbar-brand">
      <i class="fa-solid fa-building"></i>
      <span>RENTRACK</span> LGU
    </div>
    <div class="navbar-links">
      <a href="javascript:void(0)" onclick="openHelpModal()" class="btn-help">
        <i class="fa-regular fa-circle-question"></i> <span>Contact Admin</span>
      </a>
    </div>
  </nav>

  <!-- ===== HELP MODAL ===== -->
  <div class="help-modal-overlay" id="helpModal">
    <div class="help-modal">
      <i class="fa-solid fa-headset"></i>
      <h2>Need Assistance?</h2>
      <p>If you are having trouble accessing the portal or need help with your account, please contact your LGU system administrator.</p>
      <span class="contact-email"><i class="fa-regular fa-envelope"></i> admin@rentrack-lgu.gov.ph</span>
      <p style="font-size: 12px; margin-bottom: 5px;">Or call us at:</p>
      <span class="contact-email" style="font-size: 14px; color: var(--text-main);">(02) 1234-5678</span>
      <br>
      <button class="btn-close-modal" onclick="closeHelpModal()">Close</button>
    </div>
  </div>

  <!-- ===== PORTAL SPLASH ===== -->
  <div class="portal-splash" id="portalSplash" aria-hidden="true">
    <div class="timeclock" id="splashTimeclock">
      <div class="timeclock-body">
        <div class="timeclock-brand">RENTRACK LGU</div>
        <div class="timeclock-display" id="splashClock">00:00:00</div>
        <div class="timeclock-slot">
          <div class="timeclock-card" id="splashCard">
            <div class="card-hole"></div>
            <div class="card-portal" id="splashPortalName">Portal</div>
            <div class="card-stamp" id="splashStamp">
              <span class="stamp-text" id="stampTextLabel">Clocked In</span>
              <span class="stamp-time" id="splashStampTime">--:--:--</span>
            </div>
          </div>
        </div>
      </div>
      <div class="timeclock-base"></div>
    </div>
    <div class="timeclock-status" id="splashStatus">Clocking in&hellip;</div>
  </div>

  <!-- ===== MAIN ===== -->
  <main>
    <section class="panel" role="main" aria-labelledby="choose-heading">

      <!-- Logo -->
      <div class="logo-wrapper">
        <div class="logo-glow"></div>
        <div class="logo-circle">
          <img src="assets/images/images.png" alt="Municipal Logo" loading="lazy" decoding="async" onerror="this.onerror=null; this.style.display='none';">
        </div>
      </div>

      <!-- Heading -->
      <div class="heading-section">
        <h1 id="choose-heading">Welcome to <span class="highlight">Rentrack</span></h1>
        <p class="sub-headline">Unified Rental Registration, Tracking &amp; Compliance System</p>
      </div>

      <div class="divider-line"></div>

      <!-- Selector -->
      <div class="selector-label">
        <i class="fa-solid fa-arrow-right-to-bracket"></i>
        Select Your Access Portal
      </div>

      <!-- 3D WHEEL -->
      <div class="wheel-container">
        <div class="wheel" id="wheel">
          
          <a class="wheel-tile admin" href="login.php?portal=admin" data-portal="admin" style="--index: 0;">
            <div class="icon"><i class="fa-solid fa-user-shield"></i></div>
            <div class="title">Admin</div>
            <div class="desc">Full system oversight</div>
          </a>

          <a class="wheel-tile staff" href="login.php?portal=staff" data-portal="staff" style="--index: 1;">
            <div class="icon"><i class="fa-solid fa-users-gear"></i></div>
            <div class="title">Staff</div>
            <div class="desc">Daily operational tools</div>
          </a>

          <a class="wheel-tile inspector" href="login.php?portal=inspector" data-portal="inspector" style="--index: 2;">
            <div class="icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="title">Inspector</div>
            <div class="desc">Field inspections</div>
          </a>

          <a class="wheel-tile owner" href="login.php?portal=owner" data-portal="owner" style="--index: 3;">
            <div class="icon"><i class="fa-solid fa-house-chimney-user"></i></div>
            <div class="title">Owner</div>
            <div class="desc">Rental account management</div>
          </a>

        </div>
      </div>

      <!-- Controls -->
      <div class="wheel-controls">
        <button class="wheel-btn" id="prevBtn" aria-label="Previous card">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div class="wheel-indicators" id="indicators"></div>
        <button class="wheel-btn" id="nextBtn" aria-label="Next card">
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>

      <p class="footnote">
        <i class="fa-solid fa-circle-info"></i>
        Need help? Contact your LGU system administrator for assistance.
      </p>

    </section>
  </main>

  <!-- ===== FOOTER ===== -->
  <footer class="site-footer" role="contentinfo" aria-label="Site footer">
    &copy; 2026 <span class="brand">RENTRACK LGU</span>
    <span class="separator">|</span>
    Unified Rental Registration, Tracking and Compliance System
  </footer>

  <audio id="clickSound" src="assets/sounds/click.mp3" preload="auto"></audio>

  <!-- ===== SCRIPTS ===== -->
  <script>
    const clickAudio = document.getElementById('clickSound');

    function playClickSound() {
      if (clickAudio) {
        clickAudio.currentTime = 0;
        clickAudio.play().catch(error => {
          console.log("Audio play blocked or missing:", error);
        });
      }
    }

    function openHelpModal() {
      playClickSound();
      document.getElementById('helpModal').classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeHelpModal() {
      playClickSound();
      document.getElementById('helpModal').classList.remove('show');
      document.body.style.overflow = 'auto';
    }

    document.getElementById('helpModal').addEventListener('click', function(e) {
      if (e.target === this) closeHelpModal();
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeHelpModal();
    });

    (function() {
      const wheel = document.getElementById('wheel');
      const tiles = Array.from(wheel.querySelectorAll('.wheel-tile'));
      const indicatorsContainer = document.getElementById('indicators');
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');

      const TOTAL = tiles.length;
      const ANGLE = 360 / TOTAL;
      let currentIndex = 0;
      let isAnimating = false;

      const splash = document.getElementById('portalSplash');
      const splashClock = document.getElementById('splashClock');
      const splashCard = document.getElementById('splashCard');
      const splashPortalName = document.getElementById('splashPortalName');
      const splashStamp = document.getElementById('splashStamp');
      const splashStampTime = document.getElementById('splashStampTime');
      const splashStatus = document.getElementById('splashStatus');
      const splashTimeclock = document.getElementById('splashTimeclock');
      const stampTextLabel = document.getElementById('stampTextLabel');

      function formatClock(d) {
        const pad = n => String(n).padStart(2, '0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
      }

      function tickSplashClock() {
        splashClock.textContent = formatClock(new Date());
      }
      tickSplashClock();
      setInterval(tickSplashClock, 1000);

      function goToPortal(href, portalLabel, portalTheme) {
        playClickSound();

        splashCard.classList.remove('inserted');
        splashStamp.classList.remove('stamped');
        splashTimeclock.classList.remove('punch-shake');
        splashPortalName.textContent = portalLabel || 'Portal';
        stampTextLabel.textContent = 'CLOCKED IN';
        splashStatus.textContent = 'Inserting card…';

        splash.classList.add('show');
        splash.setAttribute('aria-hidden', 'false');

        setTimeout(() => {
          splashCard.classList.add('inserted');
          splashStatus.textContent = 'Verifying…';
        }, 40);

        setTimeout(() => {
          splashStampTime.textContent = formatClock(new Date());
          splashStamp.classList.add('stamped');
          splashTimeclock.classList.add('punch-shake');
          splashStatus.textContent = '✅ Access Granted';
        }, 1500);

        setTimeout(() => {
          window.location = href;
        }, 3000);
      }

      tiles.forEach((_, i) => {
        const dot = document.createElement('button');
        dot.className = 'wheel-indicator' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', 'Go to card ' + (i + 1));
        dot.addEventListener('click', function(e) {
          e.stopPropagation();
          playClickSound();
          goTo(i);
        });
        indicatorsContainer.appendChild(dot);
      });
      const indicators = Array.from(indicatorsContainer.children);

      function updateWheel() {
        const rotation = currentIndex * -ANGLE;
        wheel.style.transform = 'rotateY(' + rotation + 'deg)';

        tiles.forEach((tile, i) => {
          tile.classList.toggle('active-card', i === currentIndex);
        });

        indicators.forEach((dot, i) => {
          dot.classList.toggle('active', i === currentIndex);
        });
      }

      function goTo(index) {
        if (isAnimating || index === currentIndex) return;
        isAnimating = true;
        currentIndex = index;
        updateWheel();
        setTimeout(() => { isAnimating = false; }, 800);
      }

      function next() { if (!isAnimating) goTo((currentIndex + 1) % TOTAL); }
      function prev() { if (!isAnimating) goTo((currentIndex - 1 + TOTAL) % TOTAL); }

      nextBtn.addEventListener('click', function(e) { e.stopPropagation(); playClickSound(); next(); });
      prevBtn.addEventListener('click', function(e) { e.stopPropagation(); playClickSound(); prev(); });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') { playClickSound(); prev(); }
        else if (e.key === 'ArrowRight') { playClickSound(); next(); }
        else if (e.key === 'Enter') {
          const activeTile = tiles[currentIndex];
          if (activeTile) {
            const label = activeTile.querySelector('.title')?.textContent || 'Portal';
            const theme = activeTile.dataset.portal || 'admin';
            goToPortal(activeTile.getAttribute('href'), label, theme);
          }
        }
      });

      let mouseDown = false;
      let mouseStartX = 0;
      wheel.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        mouseDown = true;
        mouseStartX = e.clientX;
        wheel.style.cursor = 'grabbing';
        e.preventDefault();
      });

      window.addEventListener('mousemove', function(e) {
        if (!mouseDown || isAnimating) return;
        const delta = e.clientX - mouseStartX;
        if (Math.abs(delta) > 50) {
          playClickSound();
          if (delta > 0) prev(); else next();
          mouseDown = false;
          wheel.style.cursor = 'grab';
        }
      });

      window.addEventListener('mouseup', function() {
        if (mouseDown) { mouseDown = false; wheel.style.cursor = 'grab'; }
      });

      let touchStartX = 0, touchStartY = 0, isSwiping = false;
      wheel.addEventListener('touchstart', function(e) {
        const touch = e.touches[0];
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
        isSwiping = false;
      }, { passive: true });

      wheel.addEventListener('touchmove', function(e) {
        const touch = e.touches[0];
        const dx = touch.clientX - touchStartX;
        const dy = touch.clientY - touchStartY;
        if (Math.abs(dx) > 20 && Math.abs(dx) > Math.abs(dy)) {
          isSwiping = true;
          e.preventDefault();
        }
      }, { passive: false });

      wheel.addEventListener('touchend', function(e) {
        if (!isSwiping) return;
        const dx = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(dx) > 50) {
          playClickSound();
          if (dx > 0) prev(); else next();
        }
      }, { passive: true });

      tiles.forEach((tile) => {
        tile.addEventListener('click', function(e) {
          if (isSwiping) { e.preventDefault(); return; }
          e.preventDefault();
          const label = tile.querySelector('.title')?.textContent || 'Portal';
          const theme = tile.dataset.portal || 'admin';
          goToPortal(tile.getAttribute('href'), label, theme);
        });
      });

      updateWheel();
      wheel.style.cursor = 'grab';

      let autoRotateTimer = setInterval(() => { next(); }, 5000);

      function pauseAutoRotate() {
        clearInterval(autoRotateTimer);
        setTimeout(() => {
          autoRotateTimer = setInterval(() => { next(); }, 5000);
        }, 10000);
      }

      prevBtn.addEventListener('click', pauseAutoRotate);
      nextBtn.addEventListener('click', pauseAutoRotate);
      wheel.addEventListener('mousedown', pauseAutoRotate);
      wheel.addEventListener('touchstart', pauseAutoRotate);

    })();
  </script>
</body>
</html>