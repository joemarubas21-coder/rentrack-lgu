<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/*
|--------------------------------------------------------------------------
| CHECK REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] != "POST") {
    header("Location: add_rental.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| SANITIZE & COLLECT INPUT
|--------------------------------------------------------------------------
*/

$tracking_no        = trim($_POST['tracking_no'] ?? '');
$user_id            = (int) ($_POST['user_id'] ?? 0);
$rental_type_id     = (int) ($_POST['rental_type_id'] ?? 0);

$business_name      = trim($_POST['business_name'] ?? '');
$business_address   = trim($_POST['business_address'] ?? '');
$barangay           = trim($_POST['barangay'] ?? '');

$contact_number     = trim($_POST['contact_number'] ?? '');
$email              = trim($_POST['email'] ?? '');

$monthly_rate       = (float) ($_POST['monthly_rate'] ?? 0);
$floor_area         = (float) ($_POST['floor_area'] ?? 0);

$registration_date  = $_POST['registration_date'] ?? '';
$permit_expiry      = $_POST['permit_expiry'] ?? '';

$status             = $_POST['status'] ?? 'Pending';
$compliance_status  = $_POST['compliance_status'] ?? 'Pending';

$description        = trim($_POST['description'] ?? '');


/*
|--------------------------------------------------------------------------
| REQUIRED VALIDATION
|--------------------------------------------------------------------------
*/

if (
    empty($tracking_no) ||
    empty($user_id) ||
    empty($rental_type_id) ||
    empty($business_name) ||
    empty($business_address) ||
    empty($barangay) ||
    empty($contact_number) ||
    empty($monthly_rate) ||
    empty($registration_date) ||
    empty($permit_expiry)
) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: add_rental.php");
    exit();
}

try {
    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE TRACKING NUMBER (PDO Safe)
    |--------------------------------------------------------------------------
    */
    $checkTracking = $conn->prepare("SELECT id FROM rentals WHERE tracking_no = :tracking_no LIMIT 1");
    $checkTracking->execute([':tracking_no' => $tracking_no]);
    if ($checkTracking->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = "Tracking number already exists.";
        header("Location: add_rental.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE BUSINESS NAME (PDO Safe)
    |--------------------------------------------------------------------------
    */
    $checkBusiness = $conn->prepare("SELECT id FROM rentals WHERE business_name = :business_name AND (is_deleted = '0' OR is_deleted = FALSE) LIMIT 1");
    $checkBusiness->execute([':business_name' => $business_name]);
    if ($checkBusiness->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = "Business name is already registered.";
        header("Location: add_rental.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK OWNER EXISTS (PDO Safe)
    |--------------------------------------------------------------------------
    */
    $ownerCheck = $conn->prepare("SELECT id FROM users WHERE id = :user_id AND role = 'owner' AND status = 'Active' LIMIT 1");
    $ownerCheck->execute([':user_id' => $user_id]);
    if (!$ownerCheck->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = "Selected owner does not exist or is inactive.";
        header("Location: add_rental.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK RENTAL TYPE EXISTS (PDO Safe)
    |--------------------------------------------------------------------------
    */
    $typeCheck = $conn->prepare("SELECT id FROM rental_types WHERE id = :rental_type_id LIMIT 1");
    $typeCheck->execute([':rental_type_id' => $rental_type_id]);
    if (!$typeCheck->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = "Invalid rental type selected.";
        header("Location: add_rental.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database validation error: " . safe_error($e);
    header("Location: add_rental.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| DATE VALIDATION
|--------------------------------------------------------------------------
*/

if (strtotime($permit_expiry) < strtotime($registration_date)) {
    $_SESSION['error'] = "Permit expiry date cannot be earlier than the registration date.";
    header("Location: add_rental.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| MONTHLY RATE VALIDATION
|--------------------------------------------------------------------------
*/

if ($monthly_rate < 0) {
    $_SESSION['error'] = "Monthly rental rate must not be negative.";
    header("Location: add_rental.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| FLOOR AREA VALIDATION
|--------------------------------------------------------------------------
*/

if ($floor_area < 0) {
    $_SESSION['error'] = "Floor area must not be negative.";
    header("Location: add_rental.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| INSERT RENTAL & NOTIFICATION (PDO Prepared Statements)
|--------------------------------------------------------------------------
*/

try {
    $sql = "INSERT INTO rentals (
        tracking_no,
        user_id,
        rental_type_id,
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

        // Create a notification for the owner gamit ang PDO prepared statement
        $notifTitle = "Rental Registered";
        $notifMsg   = "Your rental \"{$business_name}\" was successfully registered.";
        
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
            VALUES (:user_id, :title, :message, 'Rental', :related_id, 0, 'Unread', NOW())");
        $notifStmt->execute([
            ':user_id'   => $user_id,
            ':title'     => $notifTitle,
            ':message'   => $notifMsg,
            ':related_id' => $newRentalId
        ]);

        $_SESSION['success'] = "Rental registered successfully.";
        header("Location: rentals.php");
        exit();
    } else {
        $_SESSION['error'] = "Unable to save rental.";
        header("Location: add_rental.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Unable to save rental.<br>" . safe_error($e);
    header("Location: add_rental.php");
    exit();
}
?>