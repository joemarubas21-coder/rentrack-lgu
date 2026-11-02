<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ==========================
// STAFF INFORMATION
// ==========================

$staffName = $_SESSION['fullname'] ?? "Staff";
$staffId = (int) ($_SESSION['user_id'] ?? 0);

// ==========================
// CHECK IF POST REQUEST
// ==========================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add_rental.php");
    exit();
}

if (
    !isset($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = 'Invalid session token. Please refresh the page and try again.';
    header("Location: add_rental.php");
    exit();
}

// ==========================
// COLLECT INPUTS
// ==========================

$user_id = (int) ($_POST['user_id'] ?? 0);
$rental_type_id = (int) ($_POST['rental_type_id'] ?? 0);
$rental_name = trim($_POST['rental_name'] ?? '');
$business_name = trim($_POST['business_name'] ?? '');
$business_address = trim($_POST['business_address'] ?? '');
$barangay = trim($_POST['barangay'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$monthly_rate = (float) ($_POST['monthly_rate'] ?? 0);
$floor_area = (float) ($_POST['floor_area'] ?? 0);
$registration_date = !empty($_POST['registration_date']) ? $_POST['registration_date'] : null;
$permit_expiry = !empty($_POST['permit_expiry']) ? $_POST['permit_expiry'] : null;
$description = trim($_POST['description'] ?? '');
$status = 'Pending';
$compliance_status = 'Pending';

$redirectPage = 'add_rental.php';

// ==========================
// VALIDATION
// ==========================

$requiredFields = [
    'user_id' => 'Rental Owner',
    'rental_type_id' => 'Rental Type',
    'rental_name' => 'Rental Establishment Name',
    'business_name' => 'Business Name',
    'business_address' => 'Business Address',
    'barangay' => 'Barangay',
    'contact_number' => 'Contact Number',
    'monthly_rate' => 'Monthly Rate',
    'registration_date' => 'Registration Date',
    'permit_expiry' => 'Permit Expiry Date'
];

foreach ($requiredFields as $field => $label) {
    if (empty($_POST[$field])) {
        $_SESSION['error'] = "Please fill in the required field: {$label}.";
        header("Location: {$redirectPage}");
        exit();
    }
}

// Validate date logic
if ($permit_expiry && $registration_date && strtotime($permit_expiry) < strtotime($registration_date)) {
    $_SESSION['error'] = 'Permit expiry date cannot be earlier than registration date.';
    header("Location: {$redirectPage}");
    exit();
}

// Validate numeric values
if ($monthly_rate < 0) {
    $_SESSION['error'] = 'Monthly rate must not be negative.';
    header("Location: {$redirectPage}");
    exit();
}

if ($floor_area < 0) {
    $_SESSION['error'] = 'Floor area must not be negative.';
    header("Location: {$redirectPage}");
    exit();
}

// Validate email format (if provided)
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Please enter a valid email address.';
    header("Location: {$redirectPage}");
    exit();
}

try {
    // ==========================
    // DATABASE VALIDATIONS (PDO Safe)
    // ==========================

    // Check if owner exists and is active
    $ownerCheck = $conn->prepare("SELECT id, fullname FROM users WHERE id = :user_id AND role = 'owner' AND status = 'Active' LIMIT 1");
    $ownerCheck->execute([':user_id' => $user_id]);
    $owner = $ownerCheck->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        $_SESSION['error'] = 'Selected owner does not exist or is not active.';
        header("Location: {$redirectPage}");
        exit();
    }

    // Check if rental type exists
    $typeCheck = $conn->prepare("SELECT id, type_name FROM rental_types WHERE id = :rental_type_id LIMIT 1");
    $typeCheck->execute([':rental_type_id' => $rental_type_id]);
    if (!$typeCheck->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = 'Invalid rental type selected.';
        header("Location: {$redirectPage}");
        exit();
    }

    // Check for duplicate business name
    $businessCheck = $conn->prepare("SELECT id FROM rentals WHERE business_name = :business_name AND (is_deleted = '0' OR is_deleted = FALSE) LIMIT 1");
    $businessCheck->execute([':business_name' => $business_name]);
    if ($businessCheck->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = 'Business name is already registered. Please use a different business name.';
        header("Location: {$redirectPage}");
        exit();
    }

    // ==========================
    // GENERATE TRACKING NUMBER (PDO Safe)
    // ==========================

    $getLast = $conn->query("SELECT tracking_no FROM rentals ORDER BY id DESC LIMIT 1");
    $last = $getLast->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastNo = (int) substr($last['tracking_no'], -6);
        $lastNo++;
    } else {
        $lastNo = 1;
    }

    $year = date("Y");
    $tracking_no = "RTR-" . $year . "-" . str_pad($lastNo, 6, "0", STR_PAD_LEFT);

    // ==========================
    // INSERT RENTAL
    // ==========================

    $sql = "INSERT INTO rentals (
        tracking_no,
        user_id,
        rental_type_id,
        rental_name,
        business_name,
        business_address,
        barangay,
        contact_number,
        email,
        monthly_rate,
        floor_area,
        registration_date,
        permit_expiry,
        status,
        compliance_status,
        description,
        created_at
    ) VALUES (
        :tracking_no,
        :user_id,
        :rental_type_id,
        :rental_name,
        :business_name,
        :business_address,
        :barangay,
        :contact_number,
        :email,
        :monthly_rate,
        :floor_area,
        :registration_date,
        :permit_expiry,
        :status,
        :compliance_status,
        :description,
        NOW()
    )";

    $stmt = $conn->prepare($sql);
    $inserted = $stmt->execute([
        ':tracking_no'       => $tracking_no,
        ':user_id'           => $user_id,
        ':rental_type_id'    => $rental_type_id,
        ':rental_name'       => $rental_name,
        ':business_name'     => $business_name,
        ':business_address'  => $business_address,
        ':barangay'          => $barangay,
        ':contact_number'    => $contact_number,
        ':email'             => $email,
        ':monthly_rate'      => $monthly_rate,
        ':floor_area'        => $floor_area,
        ':registration_date' => $registration_date,
        ':permit_expiry'     => $permit_expiry,
        ':status'            => $status,
        ':compliance_status' => $compliance_status,
        ':description'       => $description
    ]);

    if ($inserted) {
        $newRentalId = $conn->lastInsertId();
        
        // ==========================
        // CREATE NOTIFICATION FOR OWNER
        // ==========================
        
        $notifTitle = "New Rental Registration";
        $notifMsg = "Your rental \"{$business_name}\" has been successfully registered with tracking number {$tracking_no}. It is now pending for review and approval.";
        
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) 
                     VALUES (:user_id, :title, :message, 'Rental', :related_id, 0, 'Unread', NOW())");
        $notifStmt->execute([
            ':user_id'    => $user_id,
            ':title'      => $notifTitle,
            ':message'    => $notifMsg,
            ':related_id' => $newRentalId
        ]);
        
        // ==========================
        // CREATE NOTIFICATION FOR STAFF
        // ==========================
        
        $staffNotifTitle = "New Rental Application";
        $staffNotifMsg = "A new rental application has been submitted by {$owner['fullname']} for \"{$business_name}\" (Tracking: {$tracking_no}).";
        
        $staffStmt = $conn->prepare("SELECT id FROM users WHERE role = 'staff' AND status = 'Active' AND (is_deleted = '0' OR is_deleted = FALSE)");
        $staffStmt->execute();
        $staffUsers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($staffUsers) {
            foreach ($staffUsers as $staff) {
                $staffNotifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) 
                                  VALUES (:staff_id, :title, :message, 'Rental', :related_id, 0, 'Unread', NOW())");
                $staffNotifStmt->execute([
                    ':staff_id'   => $staff['id'],
                    ':title'      => $staffNotifTitle,
                    ':message'    => $staffNotifMsg,
                    ':related_id' => $newRentalId
                ]);
            }
        }
        
        // ==========================
        // LOG ACTIVITY
        // ==========================
        
        $activityLog = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) 
                        VALUES (:staff_id, 'Rental Registered', :desc, NOW())");
        $activityLog->execute([
            ':staff_id' => $staffId,
            ':desc'     => "Registered rental for {$owner['fullname']}: {$business_name} (Tracking: {$tracking_no})"
        ]);
        
        $_SESSION['success'] = 'Rental application registered successfully. Tracking number: ' . $tracking_no;
        header('Location: rentals.php');
        exit();
        
    } else {
        $_SESSION['error'] = 'Unable to save rental.';
        header("Location: {$redirectPage}");
        exit();
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Unable to save rental: ' . safe_error($e);
    header("Location: {$redirectPage}");
    exit();
}
?>