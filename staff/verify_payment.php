<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once("../config/db.php");

// Only staff or admin may verify/reject payments (owners must never reach this)
if (!in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// CSRF check (token is sent by staff/payments.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sentToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $sentToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentId = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($paymentId <= 0) {
        $response['message'] = 'Invalid payment ID.';
        echo json_encode($response);
        exit();
    }

    try {
        $stmt = $conn->prepare("
            SELECT p.*, r.business_name, r.user_id as owner_id, u.fullname as owner_name, u.email as owner_email
            FROM payments p
            INNER JOIN rentals r ON p.rental_id = r.id
            INNER JOIN users u ON r.user_id = u.id
            WHERE p.id = :payment_id
        ");
        $stmt->execute([':payment_id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $response['message'] = 'Payment not found.';
            echo json_encode($response);
            exit();
        }

        // Helper para icheck kung may kolum sa table
        $hasColumn = function($tableName, $colName) use ($conn) {
            try {
                $chk = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c");
                $chk->execute([':t' => strtolower($tableName), ':c' => strtolower($colName)]);
                return (bool)$chk->fetchColumn();
            } catch (Exception $ex) {
                return false;
            }
        };

        $hasCollectorId = $hasColumn('payments', 'collector_id');
        $hasUpdatedAt = $hasColumn('payments', 'updated_at');

        if ($action === 'verify') {
            $sqlFields = ["status = 'Verified'"];
            $params = [':payment_id' => $paymentId];

            if ($hasCollectorId) {
                $sqlFields[] = "collector_id = :collector_id";
                $params[':collector_id'] = $_SESSION['user_id'];
            }
            if ($hasUpdatedAt) {
                $sqlFields[] = "updated_at = NOW()";
            }

            $updateQuery = "UPDATE payments SET " . implode(', ', $sqlFields) . " WHERE id = :payment_id";
            $updateStmt = $conn->prepare($updateQuery);
            $success = $updateStmt->execute($params);
            
            if ($success) {
                $response['success'] = true;
                $response['message'] = 'Payment verified successfully.';
                
                $notifTitle = 'Payment Verified';
                $notifMessage = "Your payment of ₱" . number_format($payment['amount_paid'], 2) . " for '" . $payment['business_name'] . "' (OR#: " . $payment['or_number'] . ") has been verified by LGU Staff.";
                
                try {
                    $notifInsert = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                        VALUES (:user_id, :title, :message, 'Payment', :related_id, 0, 'Unread', NOW())
                    ");
                    $notifInsert->execute([
                        ':user_id'    => $payment['owner_id'],
                        ':title'      => $notifTitle,
                        ':message'    => $notifMessage,
                        ':related_id' => $paymentId
                    ]);
                } catch (Exception $e) {}

                try {
                    $adminStmt = $conn->prepare("SELECT id FROM users WHERE role ILIKE '%admin%' AND status = 'Active'");
                    $adminStmt->execute();
                    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

                    if ($admins) {
                        foreach ($admins as $admin) {
                            $adminNotifTitle = 'Payment Verified by LGU Staff';
                            $adminNotifMessage = "LGU Staff verified payment of ₱" . number_format($payment['amount_paid'], 2) . " for '" . $payment['business_name'] . "' (OR#: " . $payment['or_number'] . ")";
                            
                            $adminNotifInsert = $conn->prepare("
                                INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                                VALUES (:user_id, :title, :message, 'Payment', :related_id, 0, 'Unread', NOW())
                            ");
                            $adminNotifInsert->execute([
                                ':user_id'    => $admin['id'],
                                ':title'      => $adminNotifTitle,
                                ':message'    => $adminNotifMessage,
                                ':related_id' => $paymentId
                            ]);
                        }
                    }
                } catch (Exception $e) {}

            } else {
                $response['message'] = 'Failed to verify payment.';
            }

        } elseif ($action === 'reject') {
            $sqlFields = ["status = 'Cancelled'"];
            $params = [':payment_id' => $paymentId];

            if ($hasUpdatedAt) {
                $sqlFields[] = "updated_at = NOW()";
            }

            $updateQuery = "UPDATE payments SET " . implode(', ', $sqlFields) . " WHERE id = :payment_id";
            $updateStmt = $conn->prepare($updateQuery);
            $success = $updateStmt->execute($params);
            
            if ($success) {
                $response['success'] = true;
                $response['message'] = 'Payment rejected.';
                
                $notifTitle = 'Payment Rejected';
                $notifMessage = "Your payment for '" . $payment['business_name'] . "' (OR#: " . $payment['or_number'] . ") has been rejected. Please contact the LGU Treasurer's Office for assistance.";
                
                try {
                    $notifInsert = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                        VALUES (:user_id, :title, :message, 'Payment', :related_id, 0, 'Unread', NOW())
                    ");
                    $notifInsert->execute([
                        ':user_id'    => $payment['owner_id'],
                        ':title'      => $notifTitle,
                        ':message'    => $notifMessage,
                        ':related_id' => $paymentId
                    ]);
                } catch (Exception $e) {}

            } else {
                $response['message'] = 'Failed to reject payment.';
            }

        } else {
            $response['message'] = 'Invalid action.';
        }

    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . safe_error($e);
    }
}

echo json_encode($response);
?>