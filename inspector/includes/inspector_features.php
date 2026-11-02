<?php
/**
 * inspector_features.php
 * Helper functions para sa Inspector Portal
 * Updated: Dark orange theme aligned with dashboard.php
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../config/session.php'; app_session_start();
}

/* =========================================================
   ESCAPING HELPER
   ========================================================= */
if (!function_exists('inspector_e')) {
    function inspector_e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* =========================================================
   CSRF HELPERS
   ========================================================= */
if (!function_exists('inspector_csrf')) {
    function inspector_csrf() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('inspector_check_csrf')) {
    function inspector_check_csrf($token) {
        if (empty($token) || empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

/* =========================================================
   FLASH MESSAGE (Dark Orange Theme)
   ========================================================= */
if (!function_exists('inspector_flash')) {
    function inspector_flash($type, $message) {
        $colors = [
            'success' => ['bg' => 'rgba(16,185,129,0.15)',  'border' => 'rgba(16,185,129,0.45)',  'text' => '#34d399', 'icon' => 'fa-circle-check'],
            'danger'  => ['bg' => 'rgba(239,68,68,0.15)',   'border' => 'rgba(239,68,68,0.45)',   'text' => '#f87171', 'icon' => 'fa-circle-exclamation'],
            'warning' => ['bg' => 'rgba(245,158,11,0.15)',  'border' => 'rgba(245,158,11,0.45)',  'text' => '#fbbf24', 'icon' => 'fa-triangle-exclamation'],
            'info'    => ['bg' => 'rgba(59,130,246,0.15)',  'border' => 'rgba(59,130,246,0.45)',  'text' => '#60a5fa', 'icon' => 'fa-circle-info'],
        ];
        $c = $colors[$type] ?? $colors['info'];
        return '<div style="padding:14px 18px; border-radius:12px; margin-bottom:16px; display:flex; align-items:center; gap:12px; font-size:14px; backdrop-filter: blur(8px); background:' . $c['bg'] . '; border:1px solid ' . $c['border'] . '; color:' . $c['text'] . ';">'
            . '<i class="fa-solid ' . $c['icon'] . '"></i>'
            . '<span>' . inspector_e($message) . '</span>'
            . '</div>';
    }
}

/* =========================================================
   OWNED INSPECTION FETCH (with ownership guard)
   ========================================================= */
if (!function_exists('inspector_get_owned_inspection')) {
    function inspector_get_owned_inspection($conn, $inspectionId, $inspectorId) {
        if ($inspectionId <= 0 || $inspectorId <= 0) return false;

        try {
            $sql = "
                SELECT i.*, 
                       r.business_name, r.rental_name, r.tracking_no, r.barangay, r.business_address,
                       u.fullname AS owner_name
                FROM inspections i
                INNER JOIN rentals r ON i.rental_id = r.id
                LEFT JOIN users u ON r.user_id = u.id
                WHERE i.id = :iid AND i.inspector_id = :iid2
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':iid' => $inspectionId, ':iid2' => $inspectorId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
        } catch (PDOException $e) {
            // Fallback kung walang inspector_id column
            try {
                $sql = "
                    SELECT i.*, 
                           r.business_name, r.rental_name, r.tracking_no, r.barangay, r.business_address,
                           u.fullname AS owner_name
                    FROM inspections i
                    INNER JOIN rentals r ON i.rental_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE i.id = :iid
                    LIMIT 1
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':iid' => $inspectionId]);
                return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
            } catch (PDOException $ex) {
                return false;
            }
        }
    }
}

/* =========================================================
   PERMIT STATUS HELPER (same logic as dashboard)
   ========================================================= */
if (!function_exists('inspector_permit_status')) {
    function inspector_permit_status($expiryDate) {
        if (empty($expiryDate)) return ['label' => 'No Permit', 'class' => 'secondary'];
        $today  = time();
        $expiry = strtotime($expiryDate);
        if ($expiry < $today) {
            return ['label' => 'Expired', 'class' => 'danger'];
        } elseif (($expiry - $today) < (30 * 86400)) {
            return ['label' => 'Expiring Soon', 'class' => 'warning'];
        }
        return ['label' => 'Active', 'class' => 'success'];
    }
}

/* =========================================================
   TABLE EXISTS HELPER (PostgreSQL)
   ========================================================= */
if (!function_exists('inspector_table_exists')) {
    function inspector_table_exists($conn, $tableName) {
        try {
            $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
            $stmt->execute([':t' => strtolower($tableName)]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }
}

/* =========================================================
   STATUS BADGE HELPER
   ========================================================= */
if (!function_exists('inspector_status_badge')) {
    function inspector_status_badge($status) {
        $key = strtolower(trim((string)$status));
        $map = [
            'passed'    => 'passed',
            'completed' => 'passed',
            'scheduled' => 'scheduled',
            'ongoing'   => 'scheduled',
            'failed'    => 'failed',
            'cancelled' => 'failed',
            'pending'   => 'pending',
        ];
        $cls = $map[$key] ?? 'pending';
        return '<span class="status-badge ' . $cls . '">' . inspector_e($status) . '</span>';
    }
}