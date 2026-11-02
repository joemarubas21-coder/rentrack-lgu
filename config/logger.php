<?php
// logger.php — PostgreSQL/PDO Compatible

/**
 * Log user activity to activity_logs table
 * 
 * @param PDO $conn
 * @param int|null $user_id
 * @param string $activity
 * @param string|null $role
 * @param string|null $ip_address
 * @return bool
 */
function logActivity($conn, $user_id, $activity, $role = null, $ip_address = null) {
    try {
        // Auto-detect role if not provided
        if ($role === null && $user_id !== null && $user_id > 0) {
            $stmt = $conn->prepare("SELECT role::text FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $user_id]);
            $role = $stmt->fetchColumn() ?: null;
        }

        // Auto-detect IP if not provided
        if ($ip_address === null) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] 
                       ?? $_SERVER['REMOTE_ADDR'] 
                       ?? null;
            if ($ip_address && strpos($ip_address, ',') !== false) {
                $ip_address = trim(explode(',', $ip_address)[0]);
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, activity, role, ip_address, created_at)
            VALUES (:user_id, :activity, :role, :ip, CURRENT_TIMESTAMP)
        ");

        $stmt->execute([
            ':user_id'  => ($user_id && $user_id > 0) ? (int)$user_id : null,
            ':activity' => $activity,
            ':role'     => $role,
            ':ip'       => $ip_address
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("logActivity failed: " . $e->getMessage());
        return false;
    }
}
?>