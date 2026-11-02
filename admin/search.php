<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "includes/auth.php";
require_once "../config/db.php";

$q = trim($_GET['q'] ?? '');

$rentalResults = [];
$userResults = [];

if ($q !== '') {
    $searchTerm = '%' . $q . '%';

    try {
        // Rentals search gamit ang PDO prepared statement
        $rQuery = $conn->prepare("
            SELECT r.*, u.fullname
            FROM rentals r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.is_deleted = '0'
            AND (
                r.tracking_no LIKE :q1
                OR r.business_name LIKE :q2
                OR r.rental_name LIKE :q3
                OR u.fullname LIKE :q4
            )
            ORDER BY r.created_at DESC
            LIMIT 30
        ");
        $rQuery->execute([
            ':q1' => $searchTerm,
            ':q2' => $searchTerm,
            ':q3' => $searchTerm,
            ':q4' => $searchTerm
        ]);
        $rentalResults = $rQuery->fetchAll(PDO::FETCH_ASSOC);

        // Users search gamit ang PDO prepared statement
        $uQuery = $conn->prepare("
            SELECT * FROM users
            WHERE fullname LIKE :q1
            OR username LIKE :q2
            OR email LIKE :q3
            ORDER BY fullname ASC
            LIMIT 30
        ");
        $uQuery->execute([
            ':q1' => $searchTerm,
            ':q2' => $searchTerm,
            ':q3' => $searchTerm
        ]);
        $userResults = $uQuery->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $rentalResults = [];
        $userResults = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search Results | RENTRACK LGU</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/css/dashboard.css">

<style>
.search-results-card{ background:#fff; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:20px; margin-bottom:20px; }
.search-results-card h3{ color:#0d6efd; margin-bottom:14px; font-size:16px; }
.result-row{ display:flex; justify-content:space-between; align-items:center; padding:12px; border-radius:8px; transition:.15s; text-decoration:none; color:#111827; }
.result-row:hover{ background:#f4f7ff; }
.result-name{ font-weight:700; }
.result-sub{ font-size:12.5px; color:#64748b; }
.result-badge{ padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#dbeafe; color:#1d4ed8; }
</style>
</head>
<body>
<div class="wrapper">
<?php include "includes/sidebar.php"; ?>

<div class="main">
<?php include "includes/navbar.php"; ?>

<div class="dashboard-shell">

<div class="hero-card">
<div>
<h2><i class="fa-solid fa-magnifying-glass"></i> Search Results</h2>
<p><?php echo $q !== '' ? 'Showing results for "' . htmlspecialchars($q) . '"' : 'Enter a keyword to search rentals and users.'; ?></p>
</div>
</div>

<?php if ($q === '') { ?>

    <div class="search-results-card">
        <p style="color:#94a3b8; text-align:center; padding:30px 0;">
            <i class="fa-solid fa-magnifying-glass" style="font-size:26px; display:block; margin-bottom:10px;"></i>
            Type a tracking number, business name, or owner name in the search box above.
        </p>
    </div>

<?php } else { ?>

    <div class="search-results-card">
        <h3><i class="fa-solid fa-building"></i> Rentals (<?php echo count($rentalResults); ?>)</h3>

        <?php if (count($rentalResults) > 0) {
            foreach ($rentalResults as $r) { ?>
            <a href="view_rental.php?id=<?php echo $r['id']; ?>" class="result-row">
                <div>
                    <div class="result-name"><?php echo htmlspecialchars($r['business_name'] ?: $r['rental_name']); ?></div>
                    <div class="result-sub"><?php echo htmlspecialchars($r['tracking_no']); ?> &middot; Owner: <?php echo htmlspecialchars($r['fullname'] ?? 'N/A'); ?></div>
                </div>
                <span class="result-badge"><?php echo htmlspecialchars($r['status']); ?></span>
            </a>
        <?php } } else { ?>
            <p style="color:#94a3b8; padding:10px 0;">No matching rentals found.</p>
        <?php } ?>
    </div>

    <div class="search-results-card">
        <h3><i class="fa-solid fa-users"></i> Users (<?php echo count($userResults); ?>)</h3>

        <?php if (count($userResults) > 0) {
            foreach ($userResults as $u) { ?>
            <a href="view_user.php?id=<?php echo $u['id']; ?>" class="result-row">
                <div>
                    <div class="result-name"><?php echo htmlspecialchars($u['fullname']); ?></div>
                    <div class="result-sub">@<?php echo htmlspecialchars($u['username']); ?> &middot; <?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></div>
                </div>
                <span class="result-badge"><?php echo ucfirst(htmlspecialchars($u['role'])); ?></span>
            </a>
        <?php } } else { ?>
            <p style="color:#94a3b8; padding:10px 0;">No matching users found.</p>
        <?php } ?>
    </div>

<?php } ?>

</div>
</div>
</div>

</body>
</html>