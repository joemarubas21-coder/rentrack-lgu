<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

// Security: Regenerate session ID periodically
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

require_once("../config/db.php");
require_once("includes/auth.php");

// Validate and sanitize session values
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

// Redirect if not logged in
if ($userId === 0) {
    header('Location: login.php');
    exit;
}

$userName = isset($_SESSION['fullname']) && !empty(trim($_SESSION['fullname']))
    ? htmlspecialchars(trim($_SESSION['fullname']), ENT_QUOTES, 'UTF-8')
    : 'Owner';

$userInitial = strtoupper(substr(trim($userName), 0, 1));

// ============================================================
// GET INSPECTION DATA
// ============================================================

$inspectionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($inspectionId === 0) {
    header('Location: inspections.php');
    exit;
}

// Check if columns exist (copying the helper function from inspections.php)
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

$hasInspectionStatus = columnExists($conn, 'inspections', 'inspection_status');
$hasInspectorName = columnExists($conn, 'inspections', 'inspector_name');
$hasRecommendations = columnExists($conn, 'inspections', 'recommendations');
$hasFindings = columnExists($conn, 'inspections', 'findings');
$hasComplianceScore = columnExists($conn, 'inspections', 'compliance_score');

$inspection = null;
$errorMsg = '';

// REMOVED r.address and r.barangay to prevent "Unknown column" error
$query = "
    SELECT i.*, r.business_name, r.rental_name, r.tracking_no
    FROM inspections i
    INNER JOIN rentals r ON i.rental_id = r.id
    WHERE i.id = ? AND r.user_id = ? AND r.is_deleted = '0'
    LIMIT 1
";

$query = str_replace(['i.id = ?', 'r.user_id = ?'], ['i.id = :iid', 'r.user_id = :uid'], $query);
try {
    $stmt = $conn->prepare($query);
    $stmt->execute([':iid' => $inspectionId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $inspection = $row;
    } else {
        $errorMsg = "Inspection not found or you do not have permission to view it.";
    }
} catch (PDOException $e) {
    error_log('owner/view_inspection: ' . $e->getMessage());
    $errorMsg = "Database error occurred.";
}

// ============================================================
// HELPER FUNCTIONS (Reused from inspections.php)
// ============================================================

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function getStatusBadgeClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'passed': case 'completed': return 'status-passed';
        case 'failed': case 'rejected': return 'status-failed';
        case 'scheduled': case 'pending': return 'status-scheduled';
        case 'in-progress': case 'ongoing': return 'status-progress';
        default: return 'status-scheduled';
    }
}

function getStatusIcon($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'passed': case 'completed': return 'fa-circle-check';
        case 'failed': case 'rejected': return 'fa-circle-xmark';
        case 'scheduled': case 'pending': return 'fa-clock';
        case 'in-progress': case 'ongoing': return 'fa-spinner';
        default: return 'fa-clock';
    }
}

function formatFullDate($date) {
    if (empty($date)) return '—';
    $timestamp = strtotime($date);
    if ($timestamp === false) return '—';
    return date('F d, Y', $timestamp);
}

function formatTime($time) {
    if (empty($time)) return '—';
    $timestamp = strtotime($time);
    if ($timestamp === false) return h($time);
    return date('g:i A', $timestamp);
}

function getInspectionType($type) {
    if (empty($type)) return 'Building Inspection';
    $type = strtolower(trim($type));
    $types = [
        'fire_safety' => 'Fire Safety & Hazard', 'fire' => 'Fire Safety & Hazard',
        'structural' => 'Structural Assessment', 'electrical' => 'Electrical Safety',
        'sanitary' => 'Sanitary Inspection', 'zoning' => 'Zoning Compliance',
        'occupancy' => 'Occupancy Clearance', 'building' => 'Building Inspection',
        'health' => 'Health & Sanitary',
    ];
    return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function getInspectorName($row) {
    global $hasInspectorName;
    if ($hasInspectorName && !empty($row['inspector_name'])) {
        return h($row['inspector_name']);
    }
    $type = strtolower($row['inspection_type'] ?? '');
    if (strpos($type, 'fire') !== false) return 'Insp. Dennis Ocampo, BFP';
    elseif (strpos($type, 'structural') !== false || strpos($type, 'zoning') !== false) return 'Engr. Rafael Mendoza (City Engineering)';
    elseif (strpos($type, 'sanitary') !== false || strpos($type, 'health') !== false) return 'Dr. Maria Santos (City Health)';
    elseif (strpos($type, 'electrical') !== false) return 'Engr. Carlos Reyes (Electrical)';
    elseif (strpos($type, 'building') !== false) return 'Arch. Patricia Lim (Building Official)';
    return 'LGU Inspector';
}

function getPropertyName($row) {
    if (!empty($row['rental_name'])) return h($row['rental_name']);
    if (!empty($row['business_name'])) return h($row['business_name']);
    return '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection Details | RENTRACK LGU</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ===== DARK THEME (Same as Dashboard) ===== */
        :root {
            --rt-bg-primary: #0a0e1a;
            --rt-bg-secondary: #11162b;
            --rt-bg-card: rgba(255, 255, 255, 0.04);
            --rt-bg-card-hover: rgba(255, 255, 255, 0.07);
            --rt-border: rgba(255, 255, 255, 0.06);
            --rt-border-light: rgba(255, 255, 255, 0.10);
            --rt-text-primary: #f4f6fb;
            --rt-text-secondary: #9aa3b2;
            --rt-text-muted: #6b7394;
            --rt-purple: #8b5cf6;
            --rt-purple-glow: rgba(139, 92, 246, 0.25);
            --rt-green: #34d399;
            --rt-red: #ef4444;
            --rt-orange: #f59e0b;
            --rt-radius: 16px;
            --rt-radius-sm: 10px;
            --rt-transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --rt-sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--rt-bg-primary);
            color: var(--rt-text-primary);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        .container {
            width: 100%;
            max-width: 800px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--rt-text-secondary);
            text-decoration: none;
            margin-bottom: 24px;
            font-weight: 500;
            transition: color var(--rt-transition);
        }
        .back-link:hover { color: var(--rt-text-primary); }

        /* ===== INSPECTION CARD ===== */
        .inspection-detail-card {
            background: var(--rt-bg-secondary);
            border: 1px solid var(--rt-border);
            border-radius: var(--rt-radius);
            padding: 32px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--rt-border);
            padding-bottom: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .card-header h1 {
            font-size: 24px;
            font-weight: 800;
        }
        .card-header h1 small {
            font-size: 14px;
            font-weight: 400;
            color: var(--rt-text-muted);
            display: block;
            margin-top: 4px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
        }
        .status-badge.status-passed { background: rgba(52, 211, 153, 0.15); color: var(--rt-green); }
        .status-badge.status-scheduled { background: rgba(139, 92, 246, 0.15); color: var(--rt-purple); }
        .status-badge.status-progress { background: rgba(245, 158, 11, 0.15); color: var(--rt-orange); }
        .status-badge.status-failed { background: rgba(239, 68, 68, 0.15); color: var(--rt-red); }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 40px;
            margin-bottom: 32px;
        }

        .info-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .info-group .label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--rt-text-muted);
        }
        .info-group .value {
            font-size: 16px;
            font-weight: 500;
            color: var(--rt-text-primary);
        }
        .info-group .value .tracking {
            font-family: 'Courier New', monospace;
            color: var(--rt-text-muted);
        }

        .findings-section {
            background: var(--rt-bg-primary);
            border-radius: var(--rt-radius-sm);
            padding: 20px;
            margin-bottom: 24px;
        }
        .findings-section h4 {
            font-size: 13px;
            font-weight: 700;
            color: var(--rt-text-secondary);
            margin-bottom: 8px;
        }
        .findings-section p {
            font-size: 15px;
            line-height: 1.6;
            color: var(--rt-text-primary);
        }
        
        .back-button-wrapper {
            text-align: center;
            margin-top: 12px;
        }
        .btn-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--rt-radius-sm);
            background: linear-gradient(135deg, var(--rt-purple), #6d28d9);
            color: #fff;
            font-weight: 700;
            border: none;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 8px 24px var(--rt-purple-glow);
            transition: all var(--rt-transition);
        }
        .btn-cta:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(139, 92, 246, 0.4); }

        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            .card-header { flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="container">
        
        <a href="inspections.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Inspections
        </a>

        <?php if ($errorMsg): ?>
            <div style="background: rgba(239, 68, 68, 0.12); color: var(--rt-red); padding: 20px; border-radius: var(--rt-radius-sm); border: 1px solid rgba(239, 68, 68, 0.2);">
                <p><i class="fa-solid fa-circle-exclamation"></i> <?php echo h($errorMsg); ?></p>
            </div>
        <?php elseif ($inspection): 
            
            // Determine status
            if ($hasInspectionStatus) {
                $status = $inspection['inspection_status'] ?? 'Scheduled';
            } else {
                $status = $inspection['status'] ?? 'Scheduled';
            }
            
            $statusClass = getStatusBadgeClass($status);
            $statusIcon = getStatusIcon($status);
            $type = getInspectionType($inspection['inspection_type'] ?? '');
            $inspector = getInspectorName($inspection);
        ?>

            <div class="inspection-detail-card">
                <div class="card-header">
                    <div>
                        <h1>
                            Inspection #<?php echo h($inspection['inspection_no'] ?? 'N/A'); ?>
                            <small><?php echo h($inspection['tracking_no'] ?? 'N/A'); ?> &middot; <?php echo getPropertyName($inspection); ?></small>
                        </h1>
                    </div>
                    <span class="status-badge <?php echo $statusClass; ?>">
                        <i class="fa-regular <?php echo $statusIcon; ?>"></i>
                        <?php echo h($status); ?>
                    </span>
                </div>

                <div class="info-grid">
                    <div class="info-group">
                        <span class="label">Inspection Date</span>
                        <span class="value"><?php echo formatFullDate($inspection['inspection_date'] ?? ''); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Time</span>
                        <span class="value"><?php echo formatTime($inspection['inspection_time'] ?? ''); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Inspection Type</span>
                        <span class="value"><?php echo h($type); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Assigned Inspector</span>
                        <span class="value"><?php echo h($inspector); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Compliance Score</span>
                        <span class="value">
                            <?php 
                            if ($hasComplianceScore && isset($inspection['compliance_score'])) {
                                echo number_format((float)$inspection['compliance_score'], 2) . '%';
                            } else {
                                echo '—';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-group">
                        <span class="label">Next Scheduled Inspection</span>
                        <span class="value"><?php echo formatFullDate($inspection['next_inspection_date'] ?? ''); ?></span>
                    </div>
                    <?php if (!empty($inspection['remarks'])): ?>
                    <div class="info-group" style="grid-column: 1 / -1;">
                        <span class="label">Remarks / Notes</span>
                        <span class="value"><?php echo nl2br(h($inspection['remarks'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Inspector Findings & Recommendations -->
                <div class="findings-section">
                    <h4><i class="fa-solid fa-clipboard-list" style="margin-right: 8px;"></i> Inspector Findings</h4>
                    <p><?php echo $hasFindings && !empty($inspection['findings']) ? nl2br(h($inspection['findings'])) : 'No specific findings recorded for this inspection.'; ?></p>
                    
                    <?php if ($hasRecommendations && !empty($inspection['recommendations'])): ?>
                    <div style="border-top: 1px solid var(--rt-border); margin-top: 16px; padding-top: 16px;">
                        <h4 style="color: var(--rt-cyan);">Recommendations</h4>
                        <p style="color: var(--rt-text-primary);"><?php echo nl2br(h($inspection['recommendations'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="back-button-wrapper">
                    <a href="inspections.php" class="btn-cta">
                        <i class="fa-solid fa-arrow-left"></i> Go Back to Inspections
                    </a>
                </div>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
