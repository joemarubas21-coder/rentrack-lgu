<?php
// ==========================
// AJAX REQUEST HANDLERS (Single File - PDO / PostgreSQL)
// ==========================

// ✅ HELPER FUNCTION — inilipat sa itaas para magamit sa lahat ng handlers
function checkTableExists($conn, $tbl) {
    $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
    $stmt->execute([':t' => strtolower($tbl)]);
    return (bool)$stmt->fetchColumn();
}

if (isset($_GET['ajax_action'])) {
    require_once '../config/db.php';
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/../config/session.php'; app_session_start();
    }
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    $targetQuery = $conn->prepare("SELECT id FROM users WHERE role = 'inspector' AND id != :staff_id ORDER BY id ASC LIMIT 1");
    $targetQuery->execute([':staff_id' => $staffId]);
    $receiverId = (int)($targetQuery->fetchColumn() ?: 0);

    if ($receiverId === 0) {
        $fallbackQuery = $conn->prepare("SELECT id FROM users WHERE role = 'admin' AND id != :staff_id ORDER BY id ASC LIMIT 1");
        $fallbackQuery->execute([':staff_id' => $staffId]);
        $receiverId = (int)($fallbackQuery->fetchColumn() ?: 1);
    }

    // 1. Live Search Handler
    if ($_GET['ajax_action'] === 'search') {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) >= 2) {
            $searchTerm = '%' . $query . '%';
            $sql = "
                SELECT r.id, r.tracking_no, r.business_name, r.status, u.fullname AS owner_name
                FROM rentals r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE (r.tracking_no ILIKE :q OR r.business_name ILIKE :q OR u.fullname ILIKE :q)
                  AND r.is_deleted = FALSE
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':q' => $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results && count($results) > 0) {
                echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
                foreach ($results as $row) {
                    $status = $row['status'] ?? 'Draft';
                    $badgeBg = '#f3f4f6';
                    $badgeColor = '#4b5563';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; color: #000000; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#f3f4f6\'; this.style.borderColor=\'#dc2626\';" onmouseout="this.style.background=\'#ffffff\'; this.style.borderColor=\'#e5e7eb\';">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                    echo '<div style="font-weight: 600; font-size: 14px; color: #000000;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #9ca3af; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 12px; color: #000000;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            } else {
                echo '<div style="text-align: center; padding: 30px; color: #000000; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>';
            }
        }
        exit;
    }

    // 2. Notification Read Handler
    if ($_GET['ajax_action'] === 'read_notif') {
        if ($staffId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE (user_id = :staff_id OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
            $stmt->execute([':staff_id' => $staffId]);
            // ✅ I-reset din ang rental read time para mawala ang "New Application" badge
            $_SESSION['rentals_last_read'] = date('Y-m-d H:i:s');
            echo 'success';
        }
        exit;
    }

    // 3. LIVE NOTIFICATION CHECK (Para sa Real-time Auto-Update)
    if ($_GET['ajax_action'] === 'check_live') {
        $lastReadTime = $_SESSION['rentals_last_read'] ?? '1970-01-01 00:00:00';
        $newRentals = 0;

        if (checkTableExists($conn, 'rentals')) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE LOWER(status::text) = 'pending' AND is_deleted = FALSE AND created_at > :last_read");
            $stmt->execute([':last_read' => $lastReadTime]);
            $newRentals = (int)$stmt->fetchColumn();
        }

        $sysNotifs = 0;
        if (checkTableExists($conn, 'notifications')) {
            $stmt2 = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
            $stmt2->execute([':sid' => $staffId]);
            $sysNotifs = (int)$stmt2->fetchColumn();
        }

        echo json_encode(['total' => $newRentals + $sysNotifs]);
        exit;
    }

    // ✅ 3b. FETCH NOTIFICATIONS LIST — para live mag-refresh ang dropdown
    if ($_GET['ajax_action'] === 'fetch_notifications') {
        $notifs = [];
        $lastReadTime = $_SESSION['rentals_last_read'] ?? '1970-01-01 00:00:00';

        if (checkTableExists($conn, 'rentals')) {
            $stmt = $conn->prepare("
                SELECT id, 'New Rental Application' as title,
                       CONCAT(business_name, ' (', tracking_no, ') is waiting for review.') as message,
                       created_at, FALSE as is_read
                FROM rentals
                WHERE LOWER(status::text) = 'pending'
                  AND is_deleted = FALSE
                  AND created_at > :last_read
                ORDER BY created_at DESC LIMIT 5
            ");
            $stmt->execute([':last_read' => $lastReadTime]);
            $notifs = array_merge($notifs, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (checkTableExists($conn, 'notifications')) {
            $stmt = $conn->prepare("
                SELECT id, title, message, created_at, is_read
                FROM notifications
                WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL)
                ORDER BY created_at DESC LIMIT 5
            ");
            $stmt->execute([':sid' => $staffId]);
            $notifs = array_merge($notifs, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        usort($notifs, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        $notifs = array_slice($notifs, 0, 5);

        header('Content-Type: text/html; charset=utf-8');
        if (empty($notifs)) {
            echo '<div class="notification-item" style="text-align:center;color:#000000;padding:20px;">No notifications</div>';
        } else {
            foreach ($notifs as $n) {
                echo '<div class="notification-item">';
                echo '<strong>' . htmlspecialchars($n['title'] ?? '') . '</strong>';
                echo '<p>' . htmlspecialchars($n['message'] ?? '') . '</p>';
                echo '<small>' . date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')) . '</small>';
                echo '</div>';
            }
        }
        exit;
    }

    // 4. Chat Send Handler
    if ($_GET['ajax_action'] === 'send_chat') {
        $message = trim($_POST['message'] ?? '');
        $attachmentPath = null;

        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['attachment']['tmp_name'];
            $fileName = $_FILES['attachment']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            if (in_array($fileExtension, $allowedExtensions, true) && upload_content_ok($fileTmpPath, $fileExtension)) {
                $uploadFileDir = '../uploads/chat/';
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                $newFileName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
                $dest_path = $uploadFileDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $attachmentPath = 'uploads/chat/' . $newFileName;
                }
            }
        }

        if ($staffId > 0 && (!empty($message) || $attachmentPath !== null)) {
            if (checkTableExists($conn, 'chat_messages')) {
                $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, attachment) VALUES (:sender, :receiver, :msg, :att)");
                $stmt->execute([
                    ':sender' => $staffId,
                    ':receiver' => $receiverId,
                    ':msg' => $message,
                    ':att' => $attachmentPath
                ]);
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'Table chat_messages does not exist']);
            }
        }
        exit;
    }

    // 5. Chat Fetch Handler
    if ($_GET['ajax_action'] === 'fetch_chat') {
        if (checkTableExists($conn, 'chat_messages')) {
            $sql = "SELECT * FROM chat_messages
                    WHERE (sender_id = :staff_id AND receiver_id = :receiver_id)
                       OR (sender_id = :receiver_id AND receiver_id = :staff_id)
                    ORDER BY created_at ASC LIMIT 50";
            $res = $conn->prepare($sql);
            $res->execute([':staff_id' => $staffId, ':receiver_id' => $receiverId]);
            $messages = [];
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $messages[] = [
                    'is_outgoing' => ((int)$row['sender_id'] === $staffId),
                    'message' => $row['message'],
                    'attachment' => $row['attachment'] ? '../' . $row['attachment'] : null,
                    'time' => date('h:i A', strtotime($row['created_at']))
                ];
            }
            echo json_encode(['status' => 'success', 'messages' => $messages]);
        } else {
            echo json_encode(['status' => 'success', 'messages' => []]);
        }
        exit;
    }

    // 6. Clear Chat Handler
    if ($_GET['ajax_action'] === 'clear_chat') {
        if (checkTableExists($conn, 'chat_messages')) {
            $stmt = $conn->prepare("DELETE FROM chat_messages WHERE (sender_id = :staff_id AND receiver_id = :receiver_id) OR (sender_id = :receiver_id AND receiver_id = :staff_id)");
            $stmt->execute([':staff_id' => $staffId, ':receiver_id' => $receiverId]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Table does not exist']);
        }
        exit;
    }
}

require_once __DIR__ . '/../config/session.php'; app_session_start();

// 🔴 DAGDAG: I-save ang huling bisita kapag pinindot ang "Rental Applications" link
if (isset($_GET['read_rentals']) && $_GET['read_rentals'] == '1') {
    $_SESSION['rentals_last_read'] = date('Y-m-d H:i:s');
}

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS FOR PDO & POSTGRESQL
===================================================== */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

// ==========================
// STAFF INFORMATION
// ==========================

$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int)($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

// ==========================
// DASHBOARD COUNTS
// ==========================

$totalApplications = 0;
$pendingApplications = 0;
$approvedApplications = 0;
$rejectedApplications = 0;
$expiredPermits = 0;
$totalOwners = 0;
$pendingRenewalsCount = 0;

// 🔴 BAGONG LOGIC: Bilangin ang mga bagong Rental Applications base sa huling bisita
$newRegistrationsCount = 0;
if (tableExists($conn, 'rentals')) {
    try {
        $lastReadTime = $_SESSION['rentals_last_read'] ?? '1970-01-01 00:00:00';
        $newRegQuery = $conn->prepare("
            SELECT COUNT(*) FROM rentals 
            WHERE LOWER(status::text) = 'pending' 
              AND is_deleted = FALSE 
              AND created_at > :last_read
        ");
        $newRegQuery->execute([':last_read' => $lastReadTime]);
        $newRegistrationsCount = (int)$newRegQuery->fetchColumn();
    } catch (PDOException $e) {
        error_log("New Registrations Count Error: " . $e->getMessage());
    }
}

if (tableExists($conn, 'permit_renewals')) {
    // ✅ UPDATED: Bilangin lahat ng renewals (Pending, Approved, Rejected) para mag-match sa renewals page
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals");
    if ($pendRenewalsQuery) {
        $pendingRenewalsCount = (int)$pendRenewalsQuery->fetchColumn();
    }
}

$hasRentalDeleted = columnExists($conn, 'rentals', 'is_deleted');
$hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
$hasExpiry = columnExists($conn, 'rentals', 'permit_expiry');
$hasAddress = columnExists($conn, 'rentals', 'business_address');
$hasBarangay = columnExists($conn, 'rentals', 'barangay');

$rentalWhere = $hasRentalDeleted ? "WHERE is_deleted = FALSE" : "";
$pendingWhere = $hasRentalDeleted ? "AND is_deleted = FALSE" : "";
$userWhere = $hasUserDeleted ? "AND is_deleted = FALSE" : "";

if (tableExists($conn, 'rentals')) {
    $totalQuery = $conn->query("SELECT COUNT(*) FROM rentals $rentalWhere");
    if ($totalQuery) $totalApplications = (int)$totalQuery->fetchColumn();

    $pendingQuery = $conn->query("SELECT COUNT(*) FROM rentals WHERE status = 'Pending' $pendingWhere");
    if ($pendingQuery) $pendingApplications = (int)$pendingQuery->fetchColumn();

    $approvedQuery = $conn->query("SELECT COUNT(*) FROM rentals WHERE status = 'Approved' $pendingWhere");
    if ($approvedQuery) $approvedApplications = (int)$approvedQuery->fetchColumn();

    $rejectedQuery = $conn->query("SELECT COUNT(*) FROM rentals WHERE status = 'Rejected' $pendingWhere");
    if ($rejectedQuery) $rejectedApplications = (int)$rejectedQuery->fetchColumn();

    if ($hasExpiry) {
        $expiredQuery = $conn->query("SELECT COUNT(*) FROM rentals WHERE permit_expiry < CURRENT_DATE $pendingWhere");
        if ($expiredQuery) $expiredPermits = (int)$expiredQuery->fetchColumn();
    }
}

// Bilangin lang ang expired docs para sa chart (Inalis na ang expiring soon widget)
$expiredDocsCount = 0;
if (tableExists($conn, 'documents')) {
    $expQuery = $conn->query("SELECT COUNT(*) FROM documents WHERE permit_expiry_date IS NOT NULL AND permit_expiry_date < CURRENT_DATE");
    if ($expQuery) $expiredDocsCount = (int)$expQuery->fetchColumn();
}

$mapProperties = [];
if (tableExists($conn, 'rentals')) {
    $addressSelect = $hasAddress ? "r.business_address" : "''";
    $barangaySelect = $hasBarangay ? "r.barangay" : "''";

    $mapQuery = $conn->query("
        SELECT r.id, r.tracking_no, r.business_name, r.status, 
               $addressSelect AS business_address, 
               $barangaySelect AS barangay, 
               u.fullname AS owner_name
        FROM rentals r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.is_deleted = FALSE
        LIMIT 50
    ");
    if ($mapQuery) {
        $mapProperties = $mapQuery->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (tableExists($conn, 'users')) {
    $ownersQuery = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'owner' $userWhere");
    if ($ownersQuery) $totalOwners = (int)$ownersQuery->fetchColumn();
}

$pendingPayments = 0;
$verifiedPayments = 0;
$totalPayments = 0;
$totalRemittances = 0;

// ✅ UPDATED: Payments counting logic para mag-match sa lahat ng records (Verified, Pending, Paid)
if (tableExists($conn, 'payments')) {
    // Kunin lahat ng total count para sa "Verify payments" badge
    $totalAllPaymentsQuery = $conn->query("SELECT COUNT(*) FROM payments");
    if ($totalAllPaymentsQuery) {
        $pendingPayments = (int)$totalAllPaymentsQuery->fetchColumn(); // Gagamitin natin itong variable para lumabas sa dashboard
    }

    $verifiedPayQuery = $conn->query("SELECT COUNT(*) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($verifiedPayQuery) $verifiedPayments = (int)$verifiedPayQuery->fetchColumn();

    $totalPayQuery = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($totalPayQuery) $totalPayments = (float)$totalPayQuery->fetchColumn();
}

if (tableExists($conn, 'remittances')) {
    $remitTotalQuery = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM remittances WHERE status = 'Accepted'");
    if ($remitTotalQuery) $totalRemittances = (float)$remitTotalQuery->fetchColumn();
}

$monthlyCollections = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

if (tableExists($conn, 'payments')) {
    $monthQuery = $conn->query("
        SELECT EXTRACT(MONTH FROM payment_date) as m, SUM(amount_paid) as total 
        FROM payments 
        WHERE status IN ('Paid', 'Verified') 
          AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE) 
        GROUP BY EXTRACT(MONTH FROM payment_date)
    ");
    if ($monthQuery) {
        while ($row = $monthQuery->fetch(PDO::FETCH_ASSOC)) {
            $mIndex = (int)$row['m'] - 1;
            if(isset($monthlyCollections[$mIndex])) {
                $monthlyCollections[$mIndex] += (float)$row['total'];
            }
        }
    }
}

if (tableExists($conn, 'remittances')) {
    $remitMonthQuery = $conn->query("
        SELECT EXTRACT(MONTH FROM remittance_date) as m, SUM(total_amount) as total 
        FROM remittances 
        WHERE status = 'Accepted' 
          AND EXTRACT(YEAR FROM remittance_date) = EXTRACT(YEAR FROM CURRENT_DATE) 
        GROUP BY EXTRACT(MONTH FROM remittance_date)
    ");
    if ($remitMonthQuery) {
        while ($row = $remitMonthQuery->fetch(PDO::FETCH_ASSOC)) {
            $mIndex = (int)$row['m'] - 1;
            if(isset($monthlyCollections[$mIndex])) {
                $monthlyCollections[$mIndex] += (float)$row['total'];
            }
        }
    }
}

// ==========================
// COMBINED NOTIFICATIONS (System + New Applications)
// ==========================
$notifications = [];
$systemNotifCount = 0;

// 1. Kunin ang mga bagong Pending Rentals (New Applications)
if (tableExists($conn, 'rentals')) {
    $lastReadTime = $_SESSION['rentals_last_read'] ?? '1970-01-01 00:00:00';
    $newAppsStmt = $conn->prepare("
        SELECT id, 'New Rental Application' as title, 
               CONCAT(business_name, ' (', tracking_no, ') is waiting for review.') as message, 
               created_at, FALSE as is_read
        FROM rentals 
        WHERE LOWER(status::text) = 'pending' 
          AND is_deleted = FALSE 
          AND created_at > :last_read
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $newAppsStmt->execute([':last_read' => $lastReadTime]);
    $notifications = array_merge($notifications, $newAppsStmt->fetchAll(PDO::FETCH_ASSOC));
}

// 2. Kunin ang System Notifications
if (tableExists($conn, 'notifications')) {
    $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $stmtNotifCount->execute([':sid' => $staffId]);
    $systemNotifCount = (int)$stmtNotifCount->fetchColumn();

    $notifListStmt = $conn->prepare("
        SELECT id, title, message, created_at, is_read 
        FROM notifications 
        WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $notifListStmt->execute([':sid' => $staffId]);
    $sysNotifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $notifications = array_merge($notifications, $sysNotifications);
}

// Ayusin ang pagkakasunod-sunod (pinakabago sa taas)
usort($notifications, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Limitahan sa 5 pinakabago
$notifications = array_slice($notifications, 0, 5);

// Pagsamahin ang System Notif at New Applications (para sa bell badge)
$notificationCount = $systemNotifCount + $newRegistrationsCount;

// ==========================
// INSPECTIONS COUNTING (FIXED)
// ==========================
$myInspections = [];
$myTotalInspections = 0;
$myPendingInspections = 0;
$myCompletedInspections = 0;

if (tableExists($conn, 'inspections')) {
    try {
        // ✅ ACCURATE COUNT — walang LIMIT, walang inspector filter
        $countSql = "
            SELECT 
                COUNT(*) AS total,
                COUNT(*) FILTER (
                    WHERE LOWER(COALESCE(i.inspection_status::text, 'pending')) IN ('pending', 'scheduled')
                ) AS pending,
                COUNT(*) FILTER (
                    WHERE LOWER(COALESCE(i.inspection_status::text, '')) IN ('passed', 'completed', 'approved')
                ) AS completed
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            " . ($hasRentalDeleted ? "WHERE r.is_deleted = FALSE" : "") . "
        ";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute();
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

        $myTotalInspections     = (int)($counts['total'] ?? 0);
        $myPendingInspections   = (int)($counts['pending'] ?? 0);
        $myCompletedInspections = (int)($counts['completed'] ?? 0);

        // ✅ RECENT 5 INSPECTIONS — para sa table display lang
        $inspStmt = $conn->prepare("
            SELECT i.*, r.business_name, r.rental_name
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            " . ($hasRentalDeleted ? "WHERE r.is_deleted = FALSE" : "") . "
            ORDER BY i.inspection_date DESC NULLS LAST
            LIMIT 5
        ");
        $inspStmt->execute();
        $myInspections = $inspStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Inspections count error: " . $e->getMessage());
    }
}

$recentApplications = [];
if (tableExists($conn, 'rentals')) {
    $recentQuery = $conn->query("
        SELECT r.*, u.fullname AS owner_name, u.email, i.scheduled_date AS next_inspection
        FROM rentals r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN inspections i ON r.id = i.rental_id AND i.inspection_status = 'Scheduled'
        " . ($hasRentalDeleted ? "WHERE r.is_deleted = FALSE" : "") . "
        ORDER BY r.id DESC
        LIMIT 5
    ");

    if ($recentQuery) {
        $recentApplications = $recentQuery->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==========================
// EXTRA STAFF DATA
// ==========================
$barangayLabels = [];
$barangayCounts = [];

try {
    if ($hasBarangay && tableExists($conn, 'rentals')) {
        $brgyQuery = $conn->query("
            SELECT barangay, COUNT(*) AS c
            FROM rentals
            WHERE barangay IS NOT NULL AND barangay <> ''
              " . ($hasRentalDeleted ? "AND is_deleted = FALSE" : "") . "
            GROUP BY barangay
            ORDER BY c DESC
            LIMIT 6
        ");
        if ($brgyQuery) {
            while ($row = $brgyQuery->fetch(PDO::FETCH_ASSOC)) {
                $barangayLabels[] = $row['barangay'];
                $barangayCounts[] = (int)$row['c'];
            }
        }
    }

} catch (Throwable $e) {
    // Extra widgets are optional - never break the dashboard
}

// Inalis na ang Pending Remittances sa action items
$actionItems = [
    ['Review pending applications', 'rentals.php?status=Pending',        (int)$pendingApplications,  'fa-folder-open',            '#f59e0b'],
    ['Approve permit renewals',     'renewals_approval.php',             (int)$pendingRenewalsCount, 'fa-file-circle-exclamation', '#0284c7'],
    ['Verify payments',             'payments.php',                      (int)$pendingPayments,      'fa-money-bill-wave',        '#ca8a04'],
    ['My pending inspections',      'inspections.php?status=Pending',    (int)$myPendingInspections, 'fa-clipboard-check',        '#dc2626'],
];
$totalActionCount = array_sum(array_column($actionItems, 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover" />
    <meta name="theme-color" content="#dc2626" />
    <title>RENTRACK LGU · Staff Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-primary: #ffffff; /* Changed to Pure White */
            --bg-card: #ffffff;
            --border-glass: #e2e8f0;
            --text-primary: #000000; /* Changed to Black */
            --text-secondary: #000000; /* Changed to Black */
            --text-muted: #333333; /* Darker Gray */

            --primary-red: #dc2626;
            --primary-red-dark: #991b1b;
            --primary-red-light: #fecdd3;
            --red-soft: #fee2e2;

            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --radius: 20px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes borderSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #ffffff; /* Pure White */
            color: #000000; /* Black */
            overflow-x: hidden;
            -webkit-text-size-adjust: 100%;
            -moz-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        /* ✅ BODY SCROLL LOCK when drawer or modal is open */
        body.drawer-open, body.modal-open-lock { overflow: hidden !important; touch-action: none; }

        .page-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            margin: 0 !important;
            padding: 0 !important;
            padding-left: var(--safe-left);
            padding-right: var(--safe-right);
        }

        /* ============================================
           OFFICIAL GOV HEADER — RED GRADIENT
        ============================================ */
        .official-header {
            width: 100%;
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px;
            padding-top: calc(6px + var(--safe-top));
            gap: 20px;
            position: relative; z-index: 110;
            margin: 0 !important;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px; color: #fecdd3; font-size: 11px; font-weight: 600;
        }
        .official-header-badge { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); padding: 2px 10px; border-radius: 20px; display: flex; align-items: center; gap: 6px; color: #ffffff; }
        .oh-title { display: flex; align-items: center; gap: 8px; }
        .oh-sub { font-weight: 400; opacity: 0.9; }

        @media (max-width: 992px) {
            .oh-city { display: none; }
        }
        @media (max-width: 768px) {
            .official-header {
                display: grid; grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas: "title badge" "sub sub";
                gap: 3px 8px; padding: 6px 12px; text-align: left; align-items: center;
            }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }
        @media (max-width: 380px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .oh-title { font-size: 8.5px; gap: 4px; }
            .official-header-badge { font-size: 8px; padding: 2px 6px; }
            .oh-sub { font-size: 8px; }
        }

        /* ============================================
           MAIN WRAPPER
        ============================================ */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff; /* Plain White */
        }

        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 2000;
            background: #ffffff;
        }

        /* ============================================
           TOP NAVBAR — Brand | Nav | Search | Controls
        ============================================ */
        .top-navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            margin: 0 !important;
            gap: 14px;
            flex-wrap: nowrap;
            min-height: 58px;
        }

        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }

        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring {
            position: absolute; inset: -2px; border-radius: 50%;
            border: 2px dashed rgba(220, 38, 38, 0.55);
            animation: borderSpin 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--primary-red); line-height: 1.1; margin: 0; }
        .brand-text span { font-size: 9px; color: #000000; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none;
            width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid #e2e8f0; color: var(--primary-red); cursor: pointer;
            font-size: 16px;
            flex-shrink: 0;
        }
        .menu-toggle:active { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        /* ============================================
           HORIZONTAL NAV BAR
        ============================================ */
        .main-nav-bar {
            display: flex;
            align-items: center;
            background: transparent;
            border: none;
            padding: 0;
            position: relative;
            flex-shrink: 0;
            z-index: 45;
        }

        .nav-menu {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            gap: 2px;
            flex-wrap: nowrap;
        }

        .nav-item { position: relative; }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 10px;
            color: #000000;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.15s ease;
            cursor: pointer;
            white-space: nowrap;
            background: transparent;
            border: none;
            font-family: inherit;
        }

        .nav-link i.nav-ico { color: var(--primary-red); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--primary-red); background: #fff1f2; }
        .nav-link.active { color: var(--primary-red); background: #fff1f2; font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--primary-red); background: #fff1f2; }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-link .menu-badge {
            background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 999px; box-shadow: 0 0 8px rgba(251, 191, 36, 0.55);
            animation: pulseBadge 2s ease-in-out infinite;
        }
        @keyframes pulseBadge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .nav-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.10);
            padding: 8px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .nav-item.open .nav-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        .nav-item.align-right .nav-dropdown {
            left: auto;
            right: 0;
            transform: translateX(0) translateY(8px);
        }
        .nav-item.align-right.open .nav-dropdown {
            transform: translateX(0) translateY(0);
        }

        .nav-dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #000000;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        .nav-dropdown a:hover { background: #fff1f2; color: var(--primary-red); }
        .nav-dropdown a i { width: 16px; color: var(--primary-red); font-size: 14px; text-align: center; flex-shrink: 0; }
        .nav-dropdown a.danger { color: #dc2626; }
        .nav-dropdown a.danger i { color: #dc2626; }
        .nav-dropdown a .menu-badge {
            margin-left: auto; background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 8px; border-radius: 999px;
        }

        /* Mobile drawer header (hidden on desktop) */
        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto;
            display: flex;
            justify-content: center;
            min-width: 140px;
            max-width: 380px;
            padding: 0 8px;
            margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; }
        .search-container i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--primary-red); z-index: 2; font-size: 13px; }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px; border-radius: 999px;
            border: 1px solid #e2e8f0; background: #ffffff; color: #000000; font-size: 13px; outline: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: #64748b; }
        .search-container:hover input { border-color: var(--primary-red); background: #fff1f2; }

        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock { display: flex; flex-direction: column; align-items: flex-end; padding: 3px 14px; border-radius: 10px; background: #ffffff; border: 1px solid #e2e8f0; }
        .nav-clock .time { font-family: monospace; font-size: 13px; font-weight: 700; color: #000000; }
        .nav-clock .date { font-size: 10px; color: #000000; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid #e2e8f0; color: var(--primary-red); cursor: pointer; position: relative;
            flex-shrink: 0;
        }
        .nav-icon:active { background: #f8fafc; }
        .nav-icon .badge {
            position: absolute; top: -4px; right: -4px; background: #ef4444; color: #fff; font-size: 9px; font-weight: 700;
            width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff;
        }

        .profile-wrapper { position: relative; }
        .profile-btn {
            display: flex; align-items: center; gap: 10px; padding: 3px 12px 3px 3px;
            border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0; cursor: pointer;
        }
        .profile-btn:hover { background: #f8fafc; }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%; background: var(--primary-red);
            display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 800; font-size: 13px; overflow: hidden;
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #000000; }
        .profile-btn .info .role { font-size: 9px; color: var(--primary-red); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0; width: 240px;
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95); transition: all 0.25s ease; z-index: 60;
        }
        .notification-dropdown { width: 360px; padding: 0; max-height: 480px; overflow-y: auto; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; color: #000000; text-decoration: none; font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover { background: #fff1f2; color: var(--primary-red); }
        .profile-dropdown .dropdown-item.danger { color: #dc2626; }

        .notification-title { padding: 14px 20px; font-weight: 600; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #000000; position: sticky; top: 0; background: #ffffff; z-index: 1; }
        .notification-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; }
        .notification-item:last-child { border-bottom: none; }
        .notification-item strong { display: block; font-size: 13px; margin-bottom: 2px; color: #000000; }
        .notification-item p { font-size: 12px; color: #000000; margin: 0; }
        .notification-item small { display: block; font-size: 10px; color: #333333; margin-top: 4px; }

        /* ============================================
           CONTENT AREA
        ============================================ */
        .content {
            flex: 1;
            padding: 24px 28px 20px;
            padding-bottom: calc(20px + var(--safe-bottom));
            overflow-y: auto;
            background: #ffffff; /* Pure White */
            -webkit-overflow-scrolling: touch;
        }

        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .page-header h1 { font-size: 26px; font-weight: 700; color: #000000; margin: 0; }
        .page-header h1 i { color: var(--primary-red); margin-right: 10px; } /* Red Icon */
        .page-header p { color: #000000; font-size: 14px; margin-top: 4px; }
        .date-badge { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 40px; padding: 8px 18px; font-size: 13px; color: #000000; display: flex; align-items: center; gap: 8px; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }

        .stat-card {
            border-radius: var(--radius); padding: 18px 20px; display: flex; align-items: center; gap: 14px;
            transition: var(--transition); text-decoration: none; color: #000000; position: relative; overflow: hidden;
        }
        .stat-card:active { transform: scale(0.98); }
        .stat-card .icon {
            width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
        }

        /* ✅ PLAIN WHITE CARD - STATS CARDS (BLACK TEXT, RED ICONS) */
        .stat-card.bg-green,
        .stat-card.bg-orange,
        .stat-card.bg-blue,
        .stat-card.bg-yellow,
        .stat-card.bg-cyan { 
            background: #ffffff; 
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .stat-card .icon {
            background: #ffffff; 
            border: 1px solid #e2e8f0;
            color: var(--primary-red); /* Red Icon */
        }

        .stat-card .info h3 { font-size: 22px; font-weight: 800; line-height: 1.2; color: #000000; margin: 0; }
        .stat-card .info p { font-size: 11px; color: #000000; font-weight: 600; text-transform: uppercase; margin: 0; }

        .section-title { font-size: 18px; font-weight: 700; color: #000000; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .section-title::before { content: ''; width: 4px; height: 24px; border-radius: 999px; background: var(--primary-red); }

        .action-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .action-card {
            border-radius: var(--radius-sm); padding: 18px 16px; text-align: center; text-decoration: none; color: #000000;
            transition: var(--transition); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
            min-height: 100px;
        }
        .action-card:active { transform: scale(0.97); }
        .action-card i { font-size: 28px; color: var(--primary-red); } /* Red Icons */
        .action-card span { font-size: 13px; font-weight: 700; color: #000000; }
        .action-card .badge-count { background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700; padding: 2px 10px; border-radius: 999px; box-shadow: 0 0 8px rgba(251, 191, 36, 0.6); }

        /* ✅ PLAIN WHITE CARD - QUICK ACTIONS (BLACK TEXT, RED ICONS) */
        .bg-primary-action,
        .bg-secondary-action,
        .bg-info-action,
        .bg-warning-action,
        .bg-success-action,
        .bg-danger-action,
        .bg-dark-action,
        .bg-purple-action { 
            background: #ffffff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .bg-primary-action:hover,
        .bg-secondary-action:hover,
        .bg-info-action:hover,
        .bg-warning-action:hover,
        .bg-success-action:hover,
        .bg-danger-action:hover,
        .bg-dark-action:hover,
        .bg-purple-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .dashboard-row { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 20px; }

        .glass-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            padding: 20px 22px 22px;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1);
            color: #000000;
        }

        /* ✅ WHITE HEADER CARD WITH BLACK TEXT */
        .page-header.glass-card {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: none !important;
            position: relative;
            overflow: hidden;
        }
        .page-header.glass-card::after {
            display: none !important; /* Tinanggal ang pink circle */
        }
        .page-header.glass-card h1 { color: #000000 !important; position: relative; z-index: 1; }
        .page-header.glass-card h1 i { color: var(--primary-red) !important; } /* Red Icon */
        .page-header.glass-card p { color: #000000 !important; position: relative; z-index: 1; }
        .page-header.glass-card p span { color: #000000 !important; font-weight: 700; }
        .page-header.glass-card .date-badge {
            color: #000000 !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            position: relative; z-index: 1;
            font-weight: 600;
        }
        .page-header.glass-card .date-badge i { color: var(--primary-red) !important; } /* Red Icon */

        .glass-card .card-header { display: flex; align-items: center; justify-content: space-between; font-weight: 700; font-size: 15px; margin-bottom: 16px; color: #000000; }
        .glass-card .card-header i { color: var(--primary-red); margin-right: 8px; } /* Red Icon */
        .glass-card .card-header .view-all { font-size: 12px; color: #000000; text-decoration: none; }
        .glass-card .card-header .view-all:hover { color: var(--primary-red); }

        .graph-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .chart-container { position: relative; height: 220px; width: 100%; }

        .map-row { display: grid; grid-template-columns: 3fr 2fr; gap: 20px; margin-bottom: 20px; align-items: stretch; }
        .map-row > .glass-card { display: flex; flex-direction: column; min-width: 0; margin-bottom: 0 !important; }
        .map-row .chart-container { flex: 1; min-height: 200px; height: auto; }

        .charts-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        /* Binago: Isang column na lang ang staff-row para maging full width ang Needs Your Action */
        .staff-row { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px; }
        .charts-row > .glass-card, .staff-row > .glass-card { min-width: 0; }
        .charts-row .chart-container { height: 200px; }
        .empty-note { text-align: center; color: #000000; font-size: 12px; padding: 40px 10px; }

        .map-filters { display: flex; gap: 8px; margin-bottom: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 2px; scrollbar-width: none; }
        .map-filters::-webkit-scrollbar { display: none; }
        .map-filter-btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #e2e8f0; background: #ffffff; color: #000000; font-size: 12px; font-weight: 600; padding: 8px 14px; border-radius: 999px; cursor: pointer; white-space: nowrap; flex-shrink: 0; transition: all .15s ease; min-height: 38px; }
        .map-filter-btn:hover { border-color: var(--primary-red); }
        .map-filter-btn.active { background: var(--primary-red); border-color: var(--primary-red); color: #fff; }
        .map-filter-btn .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

        .status-pills { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
        .status-pills a { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 10px 12px; border-radius: 10px; background: #ffffff; border: 1px solid #e2e8f0; font-size: 12px; color: #000000; text-decoration: none; min-height: 44px; }
        .status-pills a:hover { border-color: var(--primary-red); background: #fff1f2; }
        .status-pills a:active { transform: scale(0.98); }
        .status-pills a b { color: #000000; }
        .status-pills .sp-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        .mini-list { display: flex; flex-direction: column; gap: 8px; }
        .mini-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border: 1px solid #e2e8f0; background: #ffffff; border-radius: 10px; text-decoration: none; color: #000000; min-height: 52px; transition: all .15s ease; }
        .mini-item:hover { border-color: var(--primary-red); background: #fff1f2; }
        .mini-item:active { transform: scale(0.99); }
        .mini-item .mi-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .mini-item .mi-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; color: var(--primary-red); font-size: 13px; flex-shrink: 0; border: 1px solid #e2e8f0; background: #ffffff !important; }
        .mini-item .mi-text { min-width: 0; }
        .mini-item .mi-title { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #000000; }
        .mini-item .mi-sub { font-size: 11px; color: #000000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mini-item .mi-tag { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; flex-shrink: 0; }
        .tag-red { background: #fee2e2; color: #b91c1c; }
        .tag-amber { background: #fef3c7; color: #b45309; }
        .tag-green { background: #dcfce7; color: #15803d; }
        .tag-gray { background: #f1f5f9; color: #64748b; }
        .card-header .header-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #fef3c7; color: #b45309; }

        @media (max-width: 1200px) {
            .charts-row { grid-template-columns: 1fr 1fr; }
            .charts-row > .glass-card:first-child { grid-column: 1 / -1; }
        }
        @media (max-width: 768px) {
            .map-row, .charts-row, .staff-row { gap: 14px; margin-bottom: 14px; }
            .charts-row { grid-template-columns: 1fr; }
            .charts-row > .glass-card:first-child { grid-column: auto; }
            .charts-row .chart-container { height: 180px; }
        }

        .table-wrap { overflow-x: auto; background: transparent !important; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; color: #000000 !important; background-color: transparent !important; margin-bottom: 0; }
        .table > :not(caption) > * > * { background-color: transparent !important; color: #000000 !important; box-shadow: none !important; }
        .table th { text-align: left; padding: 12px 14px; color: #000000 !important; font-weight: 700; border-bottom: 1px solid #e2e8f0 !important; font-size: 11px; text-transform: uppercase; background: transparent !important; white-space: nowrap; }
        .table td { padding: 14px !important; border-bottom: 1px solid #f1f5f9 !important; color: #000000 !important; vertical-align: middle; font-weight: 500; white-space: nowrap; }
        .table tr:hover { background: #fff1f2 !important; cursor: pointer; }
        .table td small { color: #000000 !important; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 40px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .badge-warning { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }

        .status-summary { display: flex; flex-direction: column; gap: 12px; }
        .status-summary a { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #000000; padding: 10px 12px; border-radius: 8px; text-decoration: none; min-height: 44px; }
        .status-summary a:hover { background: #fff1f2; color: #000000; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; }
        .status-dot.pending { background: #f59e0b; }
        .status-dot.approved { background: #16a34a; }
        .status-dot.rejected { background: #dc2626; }
        .status-dot.expired { background: #94a3b8; }
        .status-summary strong { margin-left: auto; color: #000000; font-size: 16px; }

        /* ============================================
           CHAT WIDGET
        ============================================ */
        .chat-widget-container { position: fixed; bottom: calc(25px + var(--safe-bottom)); right: calc(25px + var(--safe-right)); z-index: 1050; font-family: 'Inter', sans-serif; }
        .chat-toggle-btn {
            width: 55px; height: 55px; background: linear-gradient(135deg, var(--primary-red), var(--primary-red-dark));
            border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 22px;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.6); cursor: pointer; border: 2px solid #ffffff;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .chat-toggle-btn:active { transform: scale(0.95); }

        .chat-box-card {
            position: absolute; bottom: 70px; right: 0; width: 340px; height: 480px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            display: flex; flex-direction: column; overflow: hidden; opacity: 0; pointer-events: none;
            transform: translateY(15px) scale(0.95); transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .chat-box-card.active { opacity: 1; pointer-events: auto; transform: translateY(0) scale(1); }
        .chat-header {
            background: linear-gradient(135deg, #dc2626, #991b1b); padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between; color: #fff;
        }
        .chat-header .title-area { display: flex; align-items: center; gap: 10px; }
        .chat-header .status-dot { width: 9px; height: 9px; background: #34d399; border-radius: 50%; box-shadow: 0 0 8px #34d399; }
        .chat-body { flex: 1; padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; background: #ffffff; -webkit-overflow-scrolling: touch; }
        .chat-message { max-width: 85%; padding: 10px 14px; border-radius: 12px; font-size: 12.5px; line-height: 1.4; word-break: break-word; }
        .chat-message.incoming { background: #ffffff; color: #000000; align-self: flex-start; border: 1px solid #e2e8f0; }
        .chat-message.outgoing { background: var(--primary-red); color: #ffffff; font-weight: 600; align-self: flex-end; }
        .chat-message img { max-width: 100%; border-radius: 8px; margin-top: 6px; display: block; }

        .chat-footer { padding: 10px 12px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px; }
        .chat-input-row { display: flex; gap: 6px; align-items: center; }
        .chat-footer input[type="text"] {
            flex: 1; background: #ffffff; border: 1px solid #e2e8f0; padding: 10px 12px; border-radius: 8px;
            color: #000000; font-size: 14px; outline: none;
            min-height: 40px;
        }
        .chat-footer input[type="text"]:focus { border-color: var(--primary-red); background: #fff; }
        .chat-btn-action {
            background: #ffffff; border: 1px solid #e2e8f0; color: var(--primary-red);
            width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-size: 14px;
            flex-shrink: 0;
        }
        .chat-btn-action:hover { background: #fff1f2; border-color: var(--primary-red); }
        .chat-send-btn {
            background: var(--primary-red); border: none; color: #ffffff; width: 40px; height: 40px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-weight: bold;
            flex-shrink: 0;
        }
        .chat-send-btn:hover { background: var(--primary-red-dark); }
        #selectedFilePreview { font-size: 11px; color: var(--primary-red); display: none; align-items: center; gap: 6px; }

        .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #000000; flex-wrap: wrap; gap: 10px; }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .modal-content { background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 16px !important; box-shadow: 0 20px 40px rgba(0,0,0,0.1) !important; color: #000000; }
        .modal-header { border-bottom: 1px solid #f1f5f9 !important; }
        .modal-footer { border-top: 1px solid #f1f5f9 !important; }
        .modal-body { color: #000000; }
        .modal-body .text-muted { color: #333333 !important; }

        /* MOBILE CARD LIST FOR RECENT APPLICATIONS */
        .recent-apps-list { display: none; flex-direction: column; gap: 12px; }
        .recent-app-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid var(--primary-red);
            border-radius: 12px;
            padding: 14px 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .recent-app-card:active { transform: scale(0.99); }
        .recent-app-card .app-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px dashed #f1f5f9; font-size: 12.5px; gap: 10px; }
        .recent-app-card .app-row:last-child { border-bottom: none; padding-bottom: 0; }
        .recent-app-card .app-row:first-child { padding-top: 0; }
        .recent-app-card .app-label { font-size: 10px; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; }
        .recent-app-card .app-value { font-weight: 600; color: #000000; text-align: right; word-break: break-word; font-size: 12.5px; }
        .recent-app-card .app-value.green { color: #000000; font-weight: 700; }

        /* ============================================
           RESPONSIVE — DESKTOP 1400 / 1200
        ============================================ */
        @media (max-width: 1400px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .action-grid { grid-template-columns: repeat(3, 1fr); }
            .nav-link { padding: 10px 8px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 11px; }
        }

        /* ============================================
           MOBILE BREAKPOINT — DRAWER + STACK
        ============================================ */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .action-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-row { grid-template-columns: 1fr; }
            .graph-grid { grid-template-columns: 1fr; }
            .map-row { grid-template-columns: 1fr; }
            .map-row .chart-container { flex: none; min-height: 0; height: 190px; }

            .top-navbar {
                padding: 10px 14px;
                padding-top: calc(10px + var(--safe-top));
                gap: 10px;
                flex-wrap: wrap;
                min-height: 60px;
            }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; }
            .profile-btn .chevron { display: none; }
            .menu-toggle { display: flex; }

            /* ✅ SEARCH: full width, second row, sticky */
            .search-wrapper {
                order: 5;
                width: 100%;
                padding: 0;
                margin-top: 4px;
                margin-bottom: 4px;
                max-width: 100%;
                flex-basis: 100%;
            }
            .search-container { max-width: 100%; }

            /* ✅ SLIDE-IN MOBILE DRAWER with header + close */
            .main-nav-bar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 85vw;
                max-width: 320px;
                height: 100vh;
                height: 100dvh;
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
                padding: 0;
                z-index: 2001;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            }
            .main-nav-bar.mobile-open { left: 0 !important; }

            .mobile-drawer-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 18px;
                padding-top: calc(16px + var(--safe-top));
                background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
                color: #fff;
                flex-shrink: 0;
                border-bottom: 2px solid #7f1d1d;
            }
            .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
            .mobile-drawer-header .drawer-brand-logo {
                width: 32px; height: 32px; border-radius: 50%;
                background: #ffffff; color: var(--primary-red);
                display: flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 12px;
            }
            .mobile-drawer-header .drawer-brand-text h2 { font-size: 13px; font-weight: 800; margin: 0; color: #fff; }
            .mobile-drawer-header .drawer-brand-text span { font-size: 9px; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
            .mobile-drawer-header .drawer-close-btn {
                width: 34px; height: 34px; border-radius: 8px;
                background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35);
                color: #fff; cursor: pointer; font-size: 15px;
                display: flex; align-items: center; justify-content: center;
            }
            .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

            .main-nav-bar .nav-menu {
                flex-direction: column;
                width: 100%;
                justify-content: flex-start;
                gap: 4px;
                padding: 14px;
                padding-bottom: calc(20px + var(--safe-bottom));
            }
            .nav-item { width: 100%; }
            .nav-link {
                padding: 14px 16px;
                border-radius: 10px;
                justify-content: flex-start;
                font-size: 14.5px;
                min-height: 48px;
            }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            .nav-dropdown,
            .nav-item.open .nav-dropdown,
            .nav-item.align-right .nav-dropdown,
            .nav-item.align-right.open .nav-dropdown {
                position: static;
                left: auto;
                right: auto;
                transform: none;
                opacity: 1;
                visibility: visible;
                box-shadow: none;
                border: none;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc;
                margin-top: 4px;
                border-radius: 10px;
                min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.55); z-index: 1999;
                backdrop-filter: blur(3px);
                -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
            }
            .sidebar-overlay.active { display: block; opacity: 1; }

            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
            .profile-dropdown { width: 220px; }
        }

        /* ============================================
           MOBILE — TABLET-ISH 768px
        ============================================ */
        @media (max-width: 768px) {
            .content {
                padding: 16px 14px;
                padding-bottom: calc(24px + var(--safe-bottom));
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 18px;
                padding: 16px !important;
            }
            .page-header h1 { font-size: 20px; }
            .page-header p { font-size: 13px !important; }
            .date-badge { width: 100%; justify-content: center; font-size: 12px; }

            .glass-card { padding: 16px !important; border-radius: 16px !important; }
            .glass-card .card-header { font-size: 14px; flex-wrap: wrap; gap: 8px; }
            .section-title { font-size: 16px; }
            .section-title::before { height: 18px; }

            #propertyMap { height: 280px !important; }

            /* ✅ SWITCH TABLE → MOBILE CARDS */
            .table-wrap { display: none; }
            .recent-apps-list { display: flex; }

            .chart-container { height: 200px; }

            .chat-widget-container { bottom: calc(16px + var(--safe-bottom)); right: calc(16px + var(--safe-right)); }
            .chat-toggle-btn { width: 52px; height: 52px; font-size: 20px; }
            .chat-box-card { width: calc(100vw - 32px); height: 65vh; max-height: 560px; bottom: 72px; right: 0; }
            .chat-header { padding: 12px 14px; }
            .chat-body { padding: 14px; }
            .chat-footer { padding: 10px 12px; }
            .chat-message { font-size: 12.5px; padding: 10px 13px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10px; margin-top: 16px; }

            .map-controls { display: flex !important; flex-direction: column !important; align-items: stretch !important; gap: 8px !important; width: 100%; }
            .map-controls > div:first-child { width: 100% !important; min-width: 100% !important; }
            .map-controls #mapSearchInput { width: 100% !important; }
            .map-controls #locateMeBtn { width: 100%; min-height: 40px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }
        }

        /* ============================================
           SMALL PHONES — 576px
        ============================================ */
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 12px; flex-direction: row; align-items: center; gap: 10px; border-radius: 14px; min-height: 68px; }
            .stat-card .icon { width: 38px; height: 38px; font-size: 16px; border-radius: 10px; }
            .stat-card .info { min-width: 0; }
            .stat-card .info h3 { font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .stat-card .info p { font-size: 9px; line-height: 1.2; }

            .action-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
            .action-card { padding: 16px 8px; gap: 6px; border-radius: 12px; min-height: 92px; }
            .action-card i { font-size: 24px; }
            .action-card span { font-size: 11.5px; line-height: 1.2; }
            .action-card .badge-count { font-size: 9px; padding: 1px 7px; }

            .content { padding: 14px 12px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .page-header h1 { font-size: 18px; }
            .glass-card { padding: 14px !important; border-radius: 14px !important; }
            .glass-card .card-header { font-size: 13px; margin-bottom: 12px; }
            .section-title { font-size: 15px; margin-bottom: 12px; }
            .section-title::before { height: 16px; }

            #propertyMap { height: 250px !important; }
            .chart-container { height: 190px; }

            .chat-box-card { width: calc(100vw - 20px); height: 72vh; bottom: 68px; right: -6px; }
            .chat-toggle-btn { width: 48px; height: 48px; font-size: 18px; }

            .recent-app-card { padding: 12px 14px; }
            .recent-app-card .app-row { font-size: 11.5px; padding: 6px 0; }
            .recent-app-card .app-value { font-size: 11.5px; }
            .recent-app-card .app-label { font-size: 9.5px; }

            .notification-dropdown { width: calc(100vw - 20px); right: -60px; max-width: none; }

            .mini-item { padding: 12px 12px; }
            .mini-item .mi-icon { width: 34px; height: 34px; font-size: 12px; }
            .mini-item .mi-title { font-size: 12.5px; }
            .mini-item .mi-sub { font-size: 10.5px; }
        }

        /* ============================================
           VERY SMALL PHONES — 400px
        ============================================ */
        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 10px 10px; gap: 8px; min-height: 62px; }
            .stat-card .icon { width: 34px; height: 34px; font-size: 14px; }
            .stat-card .info h3 { font-size: 13px; }
            .stat-card .info p { font-size: 8.5px; }

            .action-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .action-card { padding: 14px 6px; min-height: 84px; }
            .action-card i { font-size: 22px; }
            .action-card span { font-size: 10.5px; }

            #propertyMap { height: 220px !important; }
            .chart-container { height: 175px; }

            .profile-btn .avatar { width: 30px; height: 30px; font-size: 12px; }
            .nav-icon { width: 36px; height: 36px; }

            .brand-logo { width: 34px; height: 34px; }
            .brand-logo-circle { width: 30px; height: 30px; font-size: 12px; }
            .brand-logo-ring { inset: -1px; border-width: 1.5px; }
        }

        /* ============================================
           TOUCH DEVICE HOVER FIX
        ============================================ */
        @media (hover: none) {
            .stat-card:hover,
            .action-card:hover,
            .mini-item:hover,
            .nav-dropdown a:hover { transform: none; }
        }

        /* Prevent iOS auto-zoom on input focus */
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .chat-footer input[type="text"],
            .search-container input,
            .form-control { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="page-container">
        <!-- RED GOV TOP BAR -->
        <div class="official-header">
            <div class="official-header-left">
                <div class="oh-title">
                    <i class="fa-solid fa-landmark"></i>
                    <span>REPUBLIC OF THE PHILIPPINES</span>
                </div>
                <span class="oh-sep" style="opacity: 0.7;">|</span>
                <span class="oh-sub">Local Government Unit Property &amp; Rental Compliance Portal</span>
            </div>
            <div class="official-header-right-group">
                <div class="official-header-badge">
                    <i class="fa-solid fa-certificate"></i>
                    <span>LGU CERTIFIED <span class="oh-portal">PORTAL </span>V2.1</span>
                </div>
                <span class="oh-city">City Treasurer &amp; Rentrack Online System</span>
            </div>
        </div>

        <div class="main-wrapper">

            <!-- STICKY HEADER -->
            <div class="sticky-header">
                <header class="top-navbar">
                    <div class="navbar-left">
                        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
                        <div class="nav-brand">
                            <div class="brand-logo">
                                <div class="brand-logo-ring"></div>
                                <div class="brand-logo-circle">RT</div>
                            </div>
                            <div class="brand-text">
                                <h2>RENTRACK</h2>
                                <span>LGU Staff</span>
                            </div>
                        </div>
                    </div>

                    <!-- HORIZONTAL NAV MENU (inline desktop; slide-in drawer mobile) -->
                    <nav class="main-nav-bar" id="mainNavBar">
                        <div class="mobile-drawer-header">
                            <div class="drawer-brand">
                                <div class="drawer-brand-logo">RT</div>
                                <div class="drawer-brand-text">
                                    <h2>RENTRACK</h2>
                                    <span>LGU Staff</span>
                                </div>
                            </div>
                            <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close menu">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <ul class="nav-menu">
                            <li class="nav-item">
                                <a href="dashboard.php" class="nav-link active">
                                    <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                                </a>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-folder-open nav-ico"></i> Rental Management
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="rentals.php?read_rentals=1" style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="display: flex; align-items: center; gap: 12px;">
                                            <i class="fa-solid fa-folder-open"></i> Rental Applications
                                        </span>
                                        <?php if ($newRegistrationsCount > 0): ?>
                                            <span class="menu-badge" style="background: #ef4444; color: #fff; box-shadow: 0 0 8px rgba(239, 68, 68, 0.6); margin-left: auto;"><?php echo $newRegistrationsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i> Register Rental</a>
                                    <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                    <a href="renewals_approval.php">
                                        <i class="fa-solid fa-file-circle-exclamation"></i> Permit Renewals
                                        <?php if ($pendingRenewalsCount > 0): ?>
                                            <span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="reports.php"><i class="fa-solid fa-file-invoice"></i> Reports &amp; Analytics</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-clipboard-check nav-ico"></i> Inspections
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> My Inspections</a>
                                    <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-days"></i> Inspection Schedule</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i> Payments</a>
                                </div>
                            </li>
                        </ul>
                    </nav>

                    <div class="search-wrapper">
                        <div class="search-container" data-bs-toggle="modal" data-bs-target="#searchModal" style="cursor: pointer;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="globalSearchInput" placeholder="Search tracking no, business, owner..." autocomplete="off" readonly style="cursor: pointer;">
                        </div>
                    </div>

                    <div class="navbar-right">
                        <div class="nav-clock">
                            <div class="time" id="clockTime">12:00 PM</div>
                            <div class="date"><?php echo date("M d, Y"); ?></div>
                        </div>

                        <div class="nav-icon-wrapper" style="position:relative;">
                            <button class="nav-icon" id="notifBtn">
                                <i class="fa-regular fa-bell"></i>
                                <?php if ($notificationCount > 0): ?>
                                    <span class="badge"><?php echo $notificationCount; ?></span>
                                <?php endif; ?>
                            </button>
                            <div id="notifDropdown" class="notification-dropdown">
                                <div class="notification-title">Recent Notifications</div>
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $n): ?>
                                        <div class="notification-item">
                                            <strong><?php echo htmlspecialchars($n['title'] ?? ''); ?></strong>
                                            <p><?php echo htmlspecialchars($n['message'] ?? ''); ?></p>
                                            <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-item" style="text-align:center;color:#000000;padding:20px;">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="profile-wrapper">
                            <div class="profile-btn" id="profileToggle">
                                <div class="avatar">
                                    <?php if (!empty($profilePicture) && file_exists(__DIR__ . '/' . $profilePicture)): ?>
                                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?php echo $staffInitial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="info">
                                    <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                    <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron" style="color: #000000;"></i>
                            </div>

                            <div class="profile-dropdown" id="profileDropdown">
                                <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                                <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                                <div class="dropdown-divider"></div>
                                <a href="#" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </header>
            </div>

            <main class="content">
                <!-- ✅ PLAIN WHITE HEADER CARD (RED ICONS, BLACK TEXT) -->
                <div class="page-header glass-card">
                    <div>
                        <h1><i class="fa-solid fa-gauge-high"></i> Staff Dashboard</h1>
                        <p style="font-size: 15px; font-weight: 600;">
                            <?php
                            date_default_timezone_set('Asia/Manila');
                            $hour = (int)date('G');
                            if ($hour >= 5 && $hour < 12) {
                                $greeting = "Good Morning";
                            } elseif ($hour >= 12 && $hour < 18) {
                                $greeting = "Good Afternoon";
                            } else {
                                $greeting = "Good Evening";
                            }
                            echo '<span>' . $greeting . ', ' . htmlspecialchars($staffName) . '!</span>';
                            ?>
                        </p>
                    </div>
                    <div class="date-badge"><i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?></div>
                </div>

                <!-- MAP + STATUS BAR CHART -->
                <div class="map-row">
                <div class="glass-card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="fa-solid fa-map-location-dot"></i> LGU Property Locations &amp; Inspection Route Map</span>
                        <div class="map-controls d-flex align-items-center gap-3 flex-wrap">
                            <div style="position: relative; display: flex; gap: 6px; min-width: 250px;">
                                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 13px;"></i>
                                <input type="text" id="mapSearchInput" class="form-control form-control-sm" placeholder="Search barangay, city, or place..." style="padding-left: 34px; border-radius: 8px; font-size: 12px; border: 1px solid #e2e8f0; color: #000000;">
                                <button class="btn btn-sm" id="mapSearchBtn" style="font-size: 12px; font-weight: 600; border-radius: 8px; background: var(--primary-red); border: none; padding: 0 14px; flex-shrink: 0; color: #fff;"><i class="fa-solid fa-arrow-right"></i></button>
                            </div>
                            <button class="btn btn-sm" id="locateMeBtn" style="font-size: 11px; font-weight: 600; white-space: nowrap; border: 1px solid var(--primary-red); color: var(--primary-red); background: transparent;"><i class="fa-solid fa-crosshairs me-1"></i> My GPS Location</button>
                        </div>
                    </div>
                    <div class="map-filters" id="mapFilters">
                        <button type="button" class="map-filter-btn active" data-filter="all">All</button>
                        <button type="button" class="map-filter-btn" data-filter="pending"><span class="dot" style="background:#f59e0b;"></span>Pending</button>
                        <button type="button" class="map-filter-btn" data-filter="approved"><span class="dot" style="background:#16a34a;"></span>Approved</button>
                        <button type="button" class="map-filter-btn" data-filter="rejected"><span class="dot" style="background:#ef4444;"></span>Rejected</button>
                    </div>
                    <div id="propertyMap" style="width: 100%; height: 300px; border-radius: 12px; z-index: 1;"></div>
                    <div class="form-text mt-2" style="font-size: 12px; color: #000000;">
                        <i class="fa-solid fa-circle-info me-1" style="color: var(--primary-red);"></i> Tip: Tap a marker for details or a quick route. Double-tap the map to get coordinates.
                    </div>
                </div>

                <div class="glass-card">
                    <div class="card-header"><span><i class="fa-solid fa-chart-column"></i>Rental Application Status</span></div>
                    <div class="chart-container"><canvas id="rentalStatusChart"></canvas></div>
                    <div class="status-pills">
                        <a href="rentals.php?status=Pending"><span><span class="sp-dot" style="background:#f59e0b;"></span>Pending</span><b><?php echo number_format($pendingApplications); ?></b></a>
                        <a href="rentals.php?status=Approved"><span><span class="sp-dot" style="background:#16a34a;"></span>Approved</span><b><?php echo number_format($approvedApplications); ?></b></a>
                        <a href="rentals.php?status=Rejected"><span><span class="sp-dot" style="background:#ef4444;"></span>Rejected</span><b><?php echo number_format($rejectedApplications); ?></b></a>
                        <!-- ✅ UPDATED: Expired pill now links to documents.php -->
                        <a href="documents.php?status=Expired"><span><span class="sp-dot" style="background:#94a3b8;"></span>Expired</span><b><?php echo number_format($expiredPermits + $expiredDocsCount); ?></b></a>
                    </div>
                </div>
                </div><!-- /.map-row -->

                <!-- STATS GRID (RED ICONS, BLACK TEXT) -->
                <div class="stats-grid">
                    <a href="inspections.php" class="stat-card bg-green">
                        <div class="icon"><i class="fa-solid fa-clipboard-check"></i></div>
                        <div class="info">
                            <h3><?php echo number_format($myTotalInspections); ?></h3>
                            <p>Total Inspections</p>
                        </div>
                    </a>
                    <a href="inspections.php?status=Pending" class="stat-card bg-orange">
                        <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div class="info">
                            <h3><?php echo number_format($myPendingInspections); ?></h3>
                            <p>Pending Inspections</p>
                        </div>
                    </a>
                    <a href="renewals_approval.php" class="stat-card bg-blue">
                        <div class="icon"><i class="fa-solid fa-file-circle-exclamation"></i></div>
                        <div class="info">
                            <h3><?php echo number_format($pendingRenewalsCount); ?></h3>
                            <p>Pending Renewals</p>
                        </div>
                    </a>
                    <a href="payments.php" class="stat-card bg-yellow">
                        <div class="icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                        <div class="info">
                            <h3>₱ <?php echo number_format($totalPayments, 2); ?></h3>
                            <p>Total Payments</p>
                        </div>
                    </a>
                </div>

                <div class="section-title">Quick Actions</div>
                <!-- QUICK ACTIONS (RED ICONS, BLACK TEXT) -->
                <div class="action-grid">
                    <a href="rentals.php" class="action-card bg-primary-action"><i class="fa-solid fa-folder-open"></i><span>Rental Applications</span></a>
                    <a href="renewals_approval.php" class="action-card bg-info-action"><i class="fa-solid fa-file-circle-exclamation"></i><span>Permit Renewals</span><?php if ($pendingRenewalsCount > 0): ?><span class="badge-count"><?php echo $pendingRenewalsCount; ?></span><?php endif; ?></a>
                    <a href="add_rental.php" class="action-card bg-secondary-action"><i class="fa-solid fa-plus-circle"></i><span>Register Rental</span></a>
                    <a href="documents.php" class="action-card bg-info-action"><i class="fa-solid fa-file-lines"></i><span>Documents</span></a>
                    <a href="reports.php" class="action-card bg-purple-action"><i class="fa-solid fa-file-invoice"></i><span>Reports &amp; Analytics</span></a>
                    <a href="inspection_schedule.php" class="action-card bg-warning-action"><i class="fa-solid fa-calendar-days"></i><span>Inspection Schedule</span></a>
                    <a href="inspections.php" class="action-card bg-success-action"><i class="fa-solid fa-clipboard-check"></i><span>My Inspections</span></a>
                    <!-- ✅ BONUS: Nilagyan ng badge counter ang Verify Payments sa Quick Actions -->
                    <a href="payments.php" class="action-card bg-dark-action"><i class="fa-solid fa-money-check-dollar"></i><span>Verify Payments</span><?php if ($pendingPayments > 0): ?><span class="badge-count"><?php echo $pendingPayments; ?></span><?php endif; ?></a>
                </div>

                <div class="charts-row">
                    <div class="glass-card">
                        <div class="card-header"><span><i class="fa-solid fa-chart-line"></i>Monthly Collections (<?php echo date('Y'); ?>)</span></div>
                        <div class="chart-container"><canvas id="monthlyCollectionChart"></canvas></div>
                    </div>
                    <div class="glass-card">
                        <div class="card-header"><span><i class="fa-solid fa-location-dot"></i>Top Barangays</span></div>
                        <?php if (!empty($barangayLabels)): ?>
                            <div class="chart-container"><canvas id="barangayChart"></canvas></div>
                        <?php else: ?>
                            <div class="empty-note"><i class="fa-solid fa-chart-simple d-block mb-2" style="font-size:22px;opacity:.5;"></i>No barangay data yet.</div>
                        <?php endif; ?>
                    </div>
                    <div class="glass-card">
                        <div class="card-header"><span><i class="fa-solid fa-chart-pie"></i>Payments</span></div>
                        <?php if (($verifiedPayments + $pendingPayments) > 0): ?>
                            <div class="chart-container" style="height:150px;"><canvas id="paymentChart"></canvas></div>
                            <div class="text-center mt-2" style="font-size:12px;color:#000000;">Collected: <b style="color:#000000;">&#8369;<?php echo number_format($totalPayments, 2); ?></b></div>
                        <?php else: ?>
                            <div class="empty-note"><i class="fa-solid fa-receipt d-block mb-2" style="font-size:22px;opacity:.5;"></i>No payments recorded yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="staff-row">
                    <div class="glass-card">
                        <div class="card-header">
                            <span><i class="fa-solid fa-list-check"></i>Needs Your Action</span>
                            <?php if ($totalActionCount > 0): ?><span class="header-badge"><?php echo number_format($totalActionCount); ?> open</span><?php endif; ?>
                        </div>
                        <div class="mini-list">
                            <?php foreach ($actionItems as $item): ?>
                                <a href="<?php echo $item[1]; ?>" class="mini-item">
                                    <div class="mi-left">
                                        <div class="mi-icon" style="background: #ffffff !important; color: var(--primary-red) !important; border: 1px solid #e2e8f0;"><i class="fa-solid <?php echo $item[3]; ?>"></i></div>
                                        <div class="mi-text"><div class="mi-title"><?php echo htmlspecialchars($item[0]); ?></div></div>
                                    </div>
                                    <?php if ($item[2] > 0): ?>
                                        <span class="mi-tag tag-amber"><?php echo number_format($item[2]); ?></span>
                                    <?php else: ?>
                                        <span class="mi-tag tag-green"><i class="fa-solid fa-check"></i></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="dashboard-row">
                    <div class="glass-card">
                        <div class="card-header">
                            <span><i class="fa-solid fa-list"></i>Recent Rental Applications</span>
                            <a href="rentals.php" class="view-all">View All →</a>
                        </div>

                        <!-- DESKTOP TABLE -->
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr><th>Tracking No.</th><th>Owner</th><th>Business</th><th>Status</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentApplications) > 0): ?>
                                        <?php foreach ($recentApplications as $row): ?>
                                            <tr onclick="window.location.href='view_rental.php?id=<?php echo $row['id']; ?>'">
                                                <td><strong style="color:#000000;"><?php echo htmlspecialchars($row['tracking_no'] ?? 'N/A'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($row['owner_name'] ?? 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($row['business_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php
                                                        $status = $row['status'] ?? 'Draft';
                                                        $class = 'badge-secondary';
                                                        if ($status === 'Pending') $class = 'badge-warning';
                                                        elseif ($status === 'Approved') $class = 'badge-success';
                                                        elseif ($status === 'Rejected') $class = 'badge-danger';
                                                    ?>
                                                    <span class="badge <?php echo $class; ?>"><?php echo htmlspecialchars($status); ?></span>
                                                </td>
                                                <td style="color:#000000;"><?php echo date("M d, Y", strtotime($row['created_at'] ?? 'now')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" style="text-align:center;padding:30px 0;color:#000000;">No rental applications found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- MOBILE CARDS -->
                        <div class="recent-apps-list">
                            <?php if (count($recentApplications) > 0): ?>
                                <?php foreach ($recentApplications as $row): ?>
                                    <?php
                                        $status = $row['status'] ?? 'Draft';
                                        $class = 'badge-secondary';
                                        if ($status === 'Pending') $class = 'badge-warning';
                                        elseif ($status === 'Approved') $class = 'badge-success';
                                        elseif ($status === 'Rejected') $class = 'badge-danger';
                                    ?>
                                    <div class="recent-app-card" onclick="window.location.href='view_rental.php?id=<?php echo $row['id']; ?>'">
                                        <div class="app-row">
                                            <span class="app-label">Tracking No.</span>
                                            <span class="app-value green"><?php echo htmlspecialchars($row['tracking_no'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="app-row">
                                            <span class="app-label">Business</span>
                                            <span class="app-value"><?php echo htmlspecialchars($row['business_name'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="app-row">
                                            <span class="app-label">Owner</span>
                                            <span class="app-value"><?php echo htmlspecialchars($row['owner_name'] ?? 'Unknown'); ?></span>
                                        </div>
                                        <div class="app-row">
                                            <span class="app-label">Status</span>
                                            <span class="app-value"><span class="badge <?php echo $class; ?>"><?php echo htmlspecialchars($status); ?></span></span>
                                        </div>
                                        <div class="app-row">
                                            <span class="app-label">Date</span>
                                            <span class="app-value" style="color:#000000; font-weight: 500;"><?php echo date("M d, Y", strtotime($row['created_at'] ?? 'now')); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center;padding:30px 0;color:#000000;font-size:13px;">No rental applications found.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="glass-card">
                        <div class="card-header"><span><i class="fa-solid fa-chart-pie"></i>Rental Summary</span></div>
                        <div class="status-summary">
                            <a href="rentals.php?status=Pending"><span class="status-dot pending"></span>Pending <strong><?php echo number_format($pendingApplications); ?></strong></a>
                            <a href="rentals.php?status=Approved"><span class="status-dot approved"></span>Approved <strong><?php echo number_format($approvedApplications); ?></strong></a>
                            <a href="rentals.php?status=Rejected"><span class="status-dot rejected"></span>Rejected <strong><?php echo number_format($rejectedApplications); ?></strong></a>
                            <!-- ✅ UPDATED: Rental summary expired link now goes to documents.php -->
                            <a href="documents.php?status=Expired"><span class="status-dot expired"></span>Expired <strong><?php echo number_format($expiredPermits + $expiredDocsCount); ?></strong></a>
                        </div>
                    </div>
                </div>

                <!-- FLOATING CHAT WIDGET -->
                <div class="chat-widget-container">
                    <div class="chat-box-card" id="chatBoxCard">
                        <div class="chat-header">
                            <div class="title-area">
                                <div class="status-dot"></div>
                                <div>
                                    <div class="fw-bold" style="font-size: 13.5px;">Staff Portal Live Chat</div>
                                    <div style="font-size: 10px; opacity: 0.9;">LGU Department Coordination</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm p-0 bg-transparent border-0 text-white" id="clearChatBtn" title="Clear All Messages" style="font-size: 14px; cursor:pointer;"><i class="fa-solid fa-trash-can"></i></button>
                                <button class="btn btn-sm p-0 bg-transparent border-0 text-white" id="closeChatBtn" style="font-size: 16px; cursor:pointer;" title="Close Chat"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        </div>

                        <div class="chat-body" id="chatBody">
                            <div class="chat-message incoming">Connecting to live chat...</div>
                        </div>
                        <div class="chat-footer">
                            <div id="selectedFilePreview">
                                <i class="fa-solid fa-paperclip"></i> <span id="fileNameDisplay">file.jpg</span>
                                <i class="fa-solid fa-xmark ms-auto" id="removeFileBtn" style="cursor:pointer;" title="Remove File"></i>
                            </div>
                            <div class="chat-input-row">
                                <label for="chatFileInput" class="chat-btn-action" title="Attach Document"><i class="fa-solid fa-plus"></i></label>
                                <input type="file" id="chatFileInput" style="display:none;" accept="image/*,.pdf,.doc,.docx">

                                <input type="text" id="chatInputMessage" placeholder="Type a message..." autocomplete="off">
                                <button class="chat-send-btn" id="chatSendBtn"><i class="fa-solid fa-paper-plane"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="chat-toggle-btn" id="chatToggleBtn" title="Open Staff Chat">
                        <i class="fa-solid fa-comments"></i>
                    </div>
                </div>

                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>
            </main>
        </div>
    </div>

    <!-- CLEAR CHAT CONFIRMATION MODAL -->
    <div class="modal fade" id="clearChatModal" tabindex="-1" aria-labelledby="clearChatModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="clearChatModalLabel" style="font-size: 16px; color: #000000;">
                        <i class="fa-solid fa-triangle-exclamation me-2" style="color: var(--primary-red);"></i> Clear Conversation History
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4 text-center">
                    <div class="mb-3">
                        <div style="width: 60px; height: 60px; margin: 0 auto; background: #fff1f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1.5px solid #fecdd3;">
                            <i class="fa-solid fa-trash-can" style="font-size: 24px; color: var(--primary-red);"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2 text-dark">Are you sure you want to clear all conversation history?</h5>
                    <p class="text-muted small mb-0">This action will permanently remove all messages for both parties and cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-light border px-4 py-2 text-dark" data-bs-dismiss="modal" style="font-size: 13px; font-weight: 600;">Cancel</button>
                    <button type="button" class="btn px-4 py-2" id="confirmClearChatBtn" style="font-size: 13px; font-weight: 600; background: var(--primary-red); border: none; color: #fff;">Yes, Clear All</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH POPUP MODAL -->
    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="background: #ffffff; color: #000000; border-radius: 20px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15); overflow: hidden;">
                <div class="modal-header border-bottom px-4 py-3">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="searchModalLabel" style="font-size: 15px; color: #000000;">
                        <i class="fa-solid fa-magnifying-glass" style="font-size: 14px; color: var(--primary-red);"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 16px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="width: 100%; padding: 14px 16px 14px 48px; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #ffffff; font-size: 14px; outline: none; transition: all 0.3s ease; color: #000000;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto; padding-right: 4px;">
                        <div style="text-align: center; padding: 40px 20px; color: #000000; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>
                            Type at least 2 characters to begin searching records...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        /* ============================================
           NAV MENU — INLINE DESKTOP / SLIDE-IN MOBILE
        ============================================ */
        const mainNavBar = document.getElementById('mainNavBar');
        const menuToggle = document.getElementById('menuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const drawerCloseBtn = document.getElementById('drawerCloseBtn');

        function openDrawer() {
            mainNavBar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.classList.add('drawer-open');
        }
        function closeDrawer() {
            mainNavBar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.classList.remove('drawer-open');
        }

        menuToggle.addEventListener('click', openDrawer);
        sidebarOverlay.addEventListener('click', closeDrawer);
        drawerCloseBtn?.addEventListener('click', closeDrawer);

        // ESC key closes drawer
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDrawer();
                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    item.classList.remove('open');
                });
            }
        });

        // Dropdown toggle
        document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const parent = trigger.parentElement;
                const wasOpen = parent.classList.contains('open');

                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    if (item !== parent) item.classList.remove('open');
                });

                parent.classList.toggle('open', !wasOpen);
            });
        });

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.nav-item.has-dropdown')) {
                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    item.classList.remove('open');
                });
            }
        });

        // Close drawer when actual nav link is clicked (mobile)
        mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 1200) {
                    closeDrawer();
                }
            });
        });

        // Close drawer on resize to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1200) {
                closeDrawer();
            }
        });

        /* ============================================
           CLOCK
        ============================================ */
        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            document.getElementById('clockTime').textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        /* ============================================
           NOTIFICATIONS (REAL-TIME AUTO-POLLING)
        ============================================ */
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');

        function checkLiveNotifications() {
            fetch('dashboard.php?ajax_action=check_live')
                .then(res => res.json())
                .then(data => {
                    const badge = notifBtn.querySelector('.badge');
                    if (data.total > 0) {
                        if (badge) {
                            badge.textContent = data.total;
                            badge.style.display = 'flex';
                        } else {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'badge';
                            newBadge.textContent = data.total;
                            notifBtn.appendChild(newBadge);
                        }
                    } else if (badge) {
                        badge.style.display = 'none';
                    }
                })
                .catch(err => console.error("Live check error:", err));
        }

        setInterval(checkLiveNotifications, 10000);
        checkLiveNotifications();

        // ✅ UPDATED: Refresh dropdown HTML bago mag-mark as read
        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');

            if (notifDropdown.classList.contains('show')) {
                // I-refresh muna ang listahan, tapos mark as read
                fetch('dashboard.php?ajax_action=fetch_notifications')
                    .then(res => res.text())
                    .then(html => {
                        notifDropdown.innerHTML =
                            '<div class="notification-title">Recent Notifications</div>' + html;
                        return fetch('dashboard.php?read_rentals=1');
                    })
                    .then(() => fetch('dashboard.php?ajax_action=read_notif'))
                    .then(response => response.text())
                    .then(data => {
                        const badge = notifBtn.querySelector('.badge');
                        if (badge) badge.style.display = 'none';
                    })
                    .catch(err => console.error("Error marking as read:", err));
            }
        });

        /* ============================================
           SEARCH MODAL
        ============================================ */
        const searchModalElement = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        searchModalElement.addEventListener('shown.bs.modal', function () {
            modalSearchInput.focus();
        });

        modalSearchInput.addEventListener('input', function() {
            let query = this.value.trim();
            if (query.length < 2) {
                modalSearchResults.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: #000000; font-size: 13px;">
                        <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>
                        Type at least 2 characters to begin searching records...
                    </div>
                `;
                return;
            }

            fetch('dashboard.php?ajax_action=search&q=' + encodeURIComponent(query))
                .then(res => res.text())
                .then(html => {
                    if (html.trim() !== '') {
                        modalSearchResults.innerHTML = html;
                    } else {
                        modalSearchResults.innerHTML = `
                            <div style="text-align: center; padding: 30px; color: #000000; font-size: 13px;">
                                <i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5; color: var(--primary-red);"></i> No matching results found.
                            </div>
                        `;
                    }
                });
        });

        window.addEventListener('click', () => {
            notifDropdown?.classList.remove('show');
            document.getElementById('profileDropdown')?.classList.remove('show');
        });

        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        profileToggle?.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });

        function confirmLogout() {
            Swal.fire({
                title: 'Logout', text: 'Are you sure you want to end your session?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        /* ============================================
           CHAT WIDGET
        ============================================ */
        const chatToggleBtn = document.getElementById('chatToggleBtn');
        const chatBoxCard = document.getElementById('chatBoxCard');
        const closeChatBtn = document.getElementById('closeChatBtn');
        const clearChatBtn = document.getElementById('clearChatBtn');
        const confirmClearChatBtn = document.getElementById('confirmClearChatBtn');
        const chatSendBtn = document.getElementById('chatSendBtn');
        const chatInputMessage = document.getElementById('chatInputMessage');
        const chatBody = document.getElementById('chatBody');
        const chatFileInput = document.getElementById('chatFileInput');
        const selectedFilePreview = document.getElementById('selectedFilePreview');
        const fileNameDisplay = document.getElementById('fileNameDisplay');
        const removeFileBtn = document.getElementById('removeFileBtn');

        const clearChatModal = new bootstrap.Modal(document.getElementById('clearChatModal'));

        let attachedFile = null;

        chatToggleBtn.addEventListener('click', () => {
            chatBoxCard.classList.toggle('active');
            if (chatBoxCard.classList.contains('active')) {
                chatInputMessage.focus();
                fetchChatMessages();
            }
        });

        closeChatBtn.addEventListener('click', () => {
            chatBoxCard.classList.remove('active');
        });

        clearChatBtn.addEventListener('click', () => {
            clearChatModal.show();
        });

        confirmClearChatBtn.addEventListener('click', () => {
            fetch('dashboard.php?ajax_action=clear_chat')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        clearChatModal.hide();
                        fetchChatMessages();
                    }
                });
        });

        function handleFileSelection(file) {
            if (file) {
                attachedFile = file;
                fileNameDisplay.textContent = file.name;
                selectedFilePreview.style.display = 'flex';
            }
        }

        chatFileInput.addEventListener('change', (e) => handleFileSelection(e.target.files[0]));
        removeFileBtn.addEventListener('click', () => {
            attachedFile = null;
            chatFileInput.value = '';
            selectedFilePreview.style.display = 'none';
        });

        function fetchChatMessages() {
            fetch('dashboard.php?ajax_action=fetch_chat')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        chatBody.innerHTML = '';
                        if (data.messages.length === 0) {
                            chatBody.innerHTML = '<div class="text-center text-muted small py-3 text-dark">No messages yet.</div>';
                            return;
                        }
                        data.messages.forEach(m => {
                            const msgDiv = document.createElement('div');
                            msgDiv.className = 'chat-message ' + (m.is_outgoing ? 'outgoing' : 'incoming');
                            let content = `<div>${escapeHtml(m.message)}</div>`;
                            if (m.attachment) {
                                content += `<a href="${m.attachment}" target="_blank"><img src="${m.attachment}" alt="Attachment"></a>`;
                            }
                            msgDiv.innerHTML = content;
                            chatBody.appendChild(msgDiv);
                        });
                        chatBody.scrollTop = chatBody.scrollHeight;
                    }
                });
        }

        function sendChatMessage() {
            const text = chatInputMessage.value.trim();
            if (text === '' && !attachedFile) return;

            const formData = new FormData();
            formData.append('message', text);
            if (attachedFile) formData.append('attachment', attachedFile);

            fetch('dashboard.php?ajax_action=send_chat', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        chatInputMessage.value = '';
                        attachedFile = null;
                        chatFileInput.value = '';
                        selectedFilePreview.style.display = 'none';
                        fetchChatMessages();
                    }
                });
        }

        chatSendBtn.addEventListener('click', sendChatMessage);
        chatInputMessage.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendChatMessage(); });

        setInterval(() => {
            if (chatBoxCard.classList.contains('active')) {
                fetchChatMessages();
            }
        }, 3000);

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        document.addEventListener('DOMContentLoaded', function() {
            const ctxStatus = document.getElementById('rentalStatusChart').getContext('2d');
            new Chart(ctxStatus, {
                type: 'bar',
                data: {
                    labels: ['Pending', 'Approved', 'Rejected', 'Expired'],
                    datasets: [{
                        data: [<?php echo $pendingApplications; ?>, <?php echo $approvedApplications; ?>, <?php echo $rejectedApplications; ?>, <?php echo $expiredPermits + $expiredDocsCount; ?>],
                        backgroundColor: ['#f59e0b', '#16a34a', '#ef4444', '#94a3b8'],
                        borderRadius: 8
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { legend: { display: false } }, 
                    scales: { 
                        x: { grid: { display: false }, ticks: { color: '#000000' } }, 
                        y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.1)' }, ticks: { color: '#000000', stepSize: 1 } } 
                    } 
                }
            });

            const ctxCollection = document.getElementById('monthlyCollectionChart').getContext('2d');
            new Chart(ctxCollection, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthlyLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($monthlyCollections); ?>,
                        fill: true, backgroundColor: 'rgba(220, 38, 38, 0.08)', borderColor: '#dc2626', borderWidth: 3, tension: 0.4, pointRadius: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { legend: { display: false } }, 
                    scales: { 
                        x: { grid: { display: false }, ticks: { color: '#000000' } }, 
                        y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.1)' }, ticks: { color: '#000000' } } 
                    } 
                }
            });

            const brgyEl = document.getElementById('barangayChart');
            if (brgyEl) {
                new Chart(brgyEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($barangayLabels); ?>,
                        datasets: [{ data: <?php echo json_encode($barangayCounts); ?>, backgroundColor: '#dc2626', borderRadius: 6, barThickness: 14 }]
                    },
                    options: { 
                        indexAxis: 'y', 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { legend: { display: false } },
                        scales: { 
                            x: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.1)' }, ticks: { color: '#000000', font: { size: 11 }, precision: 0 } }, 
                            y: { grid: { display: false }, ticks: { color: '#000000', font: { size: 11 } } } 
                        } 
                    }
                });
            }

            const payEl = document.getElementById('paymentChart');
            if (payEl) {
                new Chart(payEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Verified', 'Pending'],
                        datasets: [{ data: [<?php echo (int)$verifiedPayments; ?>, <?php echo (int)$pendingPayments; ?>], backgroundColor: ['#16a34a', '#f59e0b'], borderWidth: 0 }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        cutout: '68%', 
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 }, color: '#000000' } } } 
                    }
                });
            }

            /* ============================================
               LEAFLET MAP
            ============================================ */
            const map = L.map('propertyMap', {
                zoomControl: true,
                attributionControl: true
            }).setView([16.7833, 121.5333], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            const legend = L.control({ position: 'bottomright' });
            legend.onAdd = function (map) {
                const div = L.DomUtil.create('div', 'info legend');
                div.style.background = 'rgba(255, 255, 255, 0.95)';
                div.style.padding = '10px 14px';
                div.style.borderRadius = '10px';
                div.style.boxShadow = '0 4px 15px rgba(0,0,0,0.15)';
                div.style.fontFamily = "'Inter', sans-serif";
                div.style.fontSize = '12px';
                div.style.lineHeight = '1.8';
                div.style.border = '1px solid #e2e8f0';
                div.style.color = '#000000';
                div.innerHTML = `
                    <div style="font-weight: 700; margin-bottom: 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #000000;">Status Legend</div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#16a34a; border:1.5px solid #fff; box-shadow:0 0 4px rgba(0,0,0,0.2);"></span> Approved
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#f59e0b; border:1.5px solid #fff; box-shadow:0 0 4px rgba(0,0,0,0.2);"></span> Pending
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#ef4444; border:1.5px solid #fff; box-shadow:0 0 4px rgba(0,0,0,0.2);"></span> Rejected
                    </div>
                `;
                return div;
            };
            legend.addTo(map);

            function getCustomIcon(status) {
                let color = '#64748b';
                if (status === 'Approved') color = '#16a34a';
                else if (status === 'Pending') color = '#f59e0b';
                else if (status === 'Rejected') color = '#ef4444';

                return L.divIcon({
                    className: 'custom-map-marker',
                    html: `<div style="background-color: ${color}; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #ffffff; box-shadow: 0 0 10px rgba(0,0,0,0.4);"></div>`,
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                });
            }

            const properties = <?php echo json_encode($mapProperties); ?>;
            console.log('Properties loaded:', properties);

            const FALLBACK_LAT = 16.7833;
            const FALLBACK_LNG = 121.5333;

            async function geocodeAddress(address) {
                try {
                    const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&limit=1&countrycodes=ph`;
                    const response = await fetch(url);
                    const data = await response.json();
                    if (data && data.length > 0) {
                        return {
                            lat: parseFloat(data[0].lat),
                            lon: parseFloat(data[0].lon)
                        };
                    }
                } catch (error) {
                    console.error('Geocoding error:', error);
                }
                return null;
            }

            const allMarkers = [];
            let currentFilter = 'all';

            function addPropertyMarker(lat, lng, prop, addressString, isApproximate) {
                let status = prop.status || 'Draft';
                let marker = L.marker([lat, lng], { icon: getCustomIcon(status) });
                allMarkers.push({ marker: marker, status: String(status).toLowerCase() });
                if (currentFilter === 'all' || currentFilter === String(status).toLowerCase()) {
                    marker.addTo(map);
                }

                let routeUrl = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
                let approxNote = isApproximate ? ' <span style="color: #f59e0b; font-size: 10px;">(approximate)</span>' : '';

                marker.bindPopup(`
                    <div style="font-family: 'Inter', sans-serif; font-size: 12px; min-width: 200px; color: #000000;">
                        <strong style="color: #000000; font-size: 13px; display: block; margin-bottom: 2px;">${escapeHtml(prop.business_name)}</strong>
                        <span style="display: block; color: #000000;">Tracking: <b>${escapeHtml(prop.tracking_no)}</b></span>
                        <span style="display: block; color: #000000;">Owner: ${escapeHtml(prop.owner_name ?? 'N/A')}</span>
                        <span style="display: block; color: #000000;">Address: ${escapeHtml(addressString)}${approxNote}</span>
                        <span style="display: block; color: #000000; margin-bottom: 6px;">Status: <span style="font-weight: 600; text-transform: uppercase;">${escapeHtml(status)}</span></span>
                        <div style="display: flex; gap: 8px; border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px;">
                            <a href="view_rental.php?id=${prop.id}" style="color: #0284c7; text-decoration: none; font-weight: 600;">View →</a>
                            <span style="color: #cbd5e1;">|</span>
                            <a href="${routeUrl}" target="_blank" style="color: #dc2626; text-decoration: none; font-weight: 600;"><i class="fa-solid fa-diamond-turn-right"></i> Quick Route</a>
                        </div>
                    </div>
                `);
            }

            async function processProperties() {
                const bounds = L.latLngBounds();
                let markerCount = 0;

                for (let i = 0; i < properties.length; i++) {
                    const prop = properties[i];

                    let addressString = '';
                    if (prop.business_address && prop.barangay) {
                        addressString = `${prop.business_address}, ${prop.barangay}, Isabela, Philippines`;
                    } else if (prop.barangay) {
                        addressString = `${prop.barangay}, Isabela, Philippines`;
                    } else if (prop.business_address) {
                        addressString = `${prop.business_address}, Isabela, Philippines`;
                    }

                    addressString = addressString.trim();

                    let coords = null;
                    let isApproximate = false;

                    if (addressString && addressString !== 'Isabela, Philippines') {
                        coords = await geocodeAddress(addressString);
                    }

                    if (!coords) {
                        coords = { lat: FALLBACK_LAT, lon: FALLBACK_LNG };
                        isApproximate = true;
                    }

                    addPropertyMarker(coords.lat, coords.lon, prop, addressString || 'No address', isApproximate);
                    bounds.extend([coords.lat, coords.lon]);
                    markerCount++;

                    if (i < properties.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 1100));
                    }
                }

                if (markerCount > 0) {
                    map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
                }
            }

            processProperties();

            document.querySelectorAll('.map-filter-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    currentFilter = btn.dataset.filter;
                    document.querySelectorAll('.map-filter-btn').forEach(function(b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    allMarkers.forEach(function(m) {
                        const show = currentFilter === 'all' || m.status === currentFilter;
                        if (show && !map.hasLayer(m.marker)) { m.marker.addTo(map); }
                        if (!show && map.hasLayer(m.marker)) { map.removeLayer(m.marker); }
                    });
                });
            });

            map.on('dblclick', function(e) {
                let lat = e.latlng.lat.toFixed(6);
                let lng = e.latlng.lng.toFixed(6);
                Swal.fire({
                    title: 'GPS Coordinates Pinned',
                    html: `Latitude: <b>${lat}</b><br>Longitude: <b>${lng}</b>`,
                    icon: 'info',
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Okay'
                });
            });

            document.getElementById('locateMeBtn').addEventListener('click', function() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        let userLat = position.coords.latitude;
                        let userLng = position.coords.longitude;
                        map.setView([userLat, userLng], 15);
                        L.marker([userLat, userLng], {
                            icon: L.divIcon({
                                className: 'user-gps-marker',
                                html: `<div style="background-color: #3b82f6; width: 18px; height: 18px; border-radius: 50%; border: 3px solid #ffffff; box-shadow: 0 0 10px rgba(59,130,246,0.6);"></div>`,
                                iconSize: [18, 18],
                                iconAnchor: [9, 9]
                            })
                        }).addTo(map).bindPopup("<b>Ikaw ay narito (Current GPS Location)</b>").openPopup();
                    }, function() {
                        Swal.fire('Error', 'Hindi makuha ang iyong GPS location. Pakisubukang i-enable ang location permissions.', 'error');
                    });
                } else {
                    Swal.fire('Not Supported', 'Ang Geolocation ay hindi suportado ng iyong browser.', 'warning');
                }
            });

            const mapSearchBtn = document.getElementById('mapSearchBtn');
            const mapSearchInput = document.getElementById('mapSearchInput');

            function searchMapLocation() {
                const query = mapSearchInput.value.trim();
                if (!query) {
                    Swal.fire('Oops!', 'Please enter a barangay, city, or place to search.', 'warning');
                    return;
                }

                const originalBtnContent = mapSearchBtn.innerHTML;
                mapSearchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                mapSearchBtn.disabled = true;

                fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1&countrycodes=ph`)
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.length > 0) {
                            const lat = parseFloat(data[0].lat);
                            const lon = parseFloat(data[0].lon);
                            map.setView([lat, lon], 15);

                            L.marker([lat, lon], {
                                icon: L.divIcon({
                                    className: 'search-result-marker',
                                    html: `<div style="background-color: #8b5cf6; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #ffffff; box-shadow: 0 0 10px rgba(139,92,246,0.6); display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-magnifying-glass" style="color:#fff; font-size:10px;"></i></div>`,
                                    iconSize: [20, 20],
                                    iconAnchor: [10, 10]
                                })
                            }).addTo(map).bindPopup(`<b>${escapeHtml(data[0].display_name)}</b>`).openPopup();

                        } else {
                            Swal.fire('Not Found', 'No results found for that location. Please try a different search term.', 'info');
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        Swal.fire('Error', 'Could not complete the search. Please check your connection and try again.', 'error');
                    })
                    .finally(() => {
                        mapSearchBtn.innerHTML = originalBtnContent;
                        mapSearchBtn.disabled = false;
                    });
            }

            mapSearchBtn.addEventListener('click', searchMapLocation);
            mapSearchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    searchMapLocation();
                }
            });
        });
    </script>
</body>
</html>