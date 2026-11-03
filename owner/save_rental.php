<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header("Location: rentals.php");
    exit();
}

$id = (int) ($_POST['id'] ?? 0);
$rental_type_id = (int) ($_POST['rental_type_id'] ?? 0);
$rental_name = trim($_POST['rental_name'] ?? '');
$business_name = trim($_POST['business_name'] ?? '');
$business_address = trim($_POST['business_address'] ?? '');
$barangay = trim($_POST['barangay'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$business_permit_no = trim($_POST['business_permit_no'] ?? '');
$monthly_rate = (float) ($_POST['monthly_rate'] ?? 0);
$floor_area = (float) ($_POST['floor_area'] ?? 0);
$registration_date = !empty($_POST['registration_date']) ? $_POST['registration_date'] : null;
$permit_expiry = !empty($_POST['permit_expiry']) ? $_POST['permit_expiry'] : null;
$description = trim($_POST['description'] ?? '');
$status = trim($_POST['status'] ?? 'Pending');
$compliance_status = trim($_POST['compliance_status'] ?? 'Pending');

// ⭐ BAGO: Basahin ang pay_later flag mula sa form
// pay_later = '1'  → Pay Later (redirect sa rentals.php)
// pay_later = '0'  → Proceed to Payment (redirect sa payments.php)
$payLater = isset($_POST['pay_later']) && $_POST['pay_later'] === '1';

$redirectPage = $id > 0 ? "edit_rental.php?id={$id}" : 'register_rental.php';

if(empty($rental_name) || empty($business_name) || empty($business_address) || empty($barangay) || empty($contact_number) || empty($rental_type_id) || !isset($_POST['monthly_rate']) || empty($registration_date)){
    $_SESSION['error'] = 'Please fill in all required fields.';
    header("Location: {$redirectPage}");
    exit();
}

if($permit_expiry && $registration_date && strtotime($permit_expiry) < strtotime($registration_date)){
    $_SESSION['error'] = 'Permit expiry date cannot be earlier than registration date.';
    header("Location: {$redirectPage}");
    exit();
}

if($monthly_rate < 0){
    $_SESSION['error'] = 'Monthly rental rate must not be negative.';
    header("Location: {$redirectPage}");
    exit();
}

if($floor_area < 0){
    $_SESSION['error'] = 'Floor area must not be negative.';
    header("Location: {$redirectPage}");
    exit();
}

// Check if rental type exists via PDO
$typeCheck = $conn->prepare("SELECT id FROM rental_types WHERE id = :type_id LIMIT 1");
$typeCheck->execute([':type_id' => $rental_type_id]);
if(!$typeCheck->fetch()){
    $_SESSION['error'] = 'Invalid rental type selected.';
    header("Location: {$redirectPage}");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

if($id > 0){
    /* ============================
       UPDATE EXISTING RENTAL
       ============================ */
    $check = $conn->prepare("SELECT id, user_id FROM rentals WHERE id = :id AND is_deleted = FALSE LIMIT 1");
    $check->execute([':id' => $id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    
    if(!$row){
        $_SESSION['error'] = 'Rental not found.';
        header("Location: rentals.php");
        exit();
    }
    if($row['user_id'] != $user_id){
        $_SESSION['error'] = 'You are not authorized to update this rental.';
        header("Location: rentals.php");
        exit();
    }

    // Naalis na ang LIMIT 1 sa dulo para gumana sa PostgreSQL/Supabase
    $sql = "UPDATE rentals SET
        rental_type_id = :rental_type_id,
        rental_name = :rental_name,
        business_name = :business_name,
        business_address = :business_address,
        barangay = :barangay,
        contact_number = :contact_number,
        email = :email,
        business_permit_no = :business_permit_no,
        monthly_rate = :monthly_rate,
        floor_area = :floor_area,
        registration_date = :registration_date,
        permit_expiry = :permit_expiry,
        description = :description
        WHERE id = :id";

    $stmt = $conn->prepare($sql);
    $success = $stmt->execute([
        ':rental_type_id' => $rental_type_id,
        ':rental_name' => $rental_name,
        ':business_name' => $business_name,
        ':business_address' => $business_address,
        ':barangay' => $barangay,
        ':contact_number' => $contact_number,
        ':email' => $email,
        ':business_permit_no' => $business_permit_no,
        ':monthly_rate' => $monthly_rate,
        ':floor_area' => $floor_area,
        ':registration_date' => $registration_date,
        ':permit_expiry' => $permit_expiry,
        ':description' => $description,
        ':id' => $id
    ]);

    if($success){
        $_SESSION['success'] = 'Rental updated successfully.';
        if($payLater){
            header("Location: view_rental.php?id={$id}");
        } else {
            header("Location: payments.php?rental_id={$id}");
        }
        exit();
    } else {
        $_SESSION['error'] = 'Unable to update rental.';
        header("Location: edit_rental.php?id={$id}");
        exit();
    }
} else {
    /* ============================
       INSERT NEW RENTAL
       ============================ */
    $tracking_no = 'OWN' . date('YmdHis') . rand(10,99);

    $businessCheck = $conn->prepare("SELECT id FROM rentals WHERE business_name = :business_name AND is_deleted = FALSE LIMIT 1");
    $businessCheck->execute([':business_name' => $business_name]);
    if($businessCheck->fetch()){
        $_SESSION['error'] = 'Business name is already registered.';
        header("Location: register_rental.php");
        exit();
    }

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
        business_permit_no,
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
        :business_permit_no,
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
    $success = $stmt->execute([
        ':tracking_no' => $tracking_no,
        ':user_id' => $user_id,
        ':rental_type_id' => $rental_type_id,
        ':rental_name' => $rental_name,
        ':business_name' => $business_name,
        ':business_address' => $business_address,
        ':barangay' => $barangay,
        ':contact_number' => $contact_number,
        ':email' => $email,
        ':business_permit_no' => $business_permit_no,
        ':monthly_rate' => $monthly_rate,
        ':floor_area' => $floor_area,
        ':registration_date' => $registration_date,
        ':permit_expiry' => $permit_expiry,
        ':status' => $status,
        ':compliance_status' => $compliance_status,
        ':description' => $description
    ]);

    if($success){
        $newRentalId = $conn->lastInsertId();

        // ==========================
        // 1. NOTIFICATION PARA SA OWNER
        // ==========================
        $notifTitle = "Rental Registered";
        $notifMsg   = "Your rental \"{$business_name}\" was successfully registered.";
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status)
            VALUES (:user_id, :title, :message, 'Rental', :related_id, FALSE, 'Unread')");
        $notifStmt->execute([
            ':user_id' => $user_id,
            ':title' => $notifTitle,
            ':message' => $notifMsg,
            ':related_id' => $newRentalId
        ]);

        // ==========================
        // 2. NOTIFICATION PARA SA LAHAT NG STAFF (inspector + admin)
        // ==========================
        try {
            // Kunin ang owner fullname para sa notification message
            $ownerQuery = $conn->prepare("SELECT fullname FROM users WHERE id = :uid LIMIT 1");
            $ownerQuery->execute([':uid' => $user_id]);
            $ownerFullname = $ownerQuery->fetchColumn() ?: 'A rental owner';

            // Hanapin lahat ng staff (inspector + admin) maliban sa nag-register
            $staffQuery = $conn->prepare("
                SELECT id FROM users
                WHERE role IN ('inspector', 'admin')
                  AND id != :self_id
            ");
            $staffQuery->execute([':self_id' => $user_id]);
            $staffList = $staffQuery->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($staffList)) {
                $staffTitle = "🎉 New Rental Registration";
                $staffMsg   = "A new rental has been registered by {$ownerFullname}.\n"
                            . "Tracking No: {$tracking_no}\n"
                            . "Business: {$business_name}\n"
                            . "Barangay: {$barangay}\n"
                            . "Please review and verify the application.";

                $staffNotifStmt = $conn->prepare("
                    INSERT INTO notifications
                        (user_id, title, message, notification_type, related_id, is_read, status)
                    VALUES
                        (:staff_id, :title, :message, 'Rental', :related_id, FALSE, 'Unread')
                ");

                foreach ($staffList as $staffId) {
                    $staffNotifStmt->execute([
                        ':staff_id'   => (int)$staffId,
                        ':title'      => $staffTitle,
                        ':message'    => $staffMsg,
                        ':related_id' => $newRentalId,
                    ]);
                }
            }
        } catch (PDOException $e) {
            error_log("Staff Notification Error: " . $e->getMessage());
        }

        $_SESSION['success'] = 'Rental registered successfully.';

        // ⭐ BAGO: Redirect base sa pay_later flag
        if($payLater){
            // Pay Later → bumalik sa rentals list
            header("Location: rentals.php");
        } else {
            // Proceed to Payment → pumunta sa payments.php kasama ang rental_id
            header("Location: payments.php?rental_id={$newRentalId}&action=pay");
        }
        exit();
    } else {
        $_SESSION['error'] = 'Unable to save rental.';
        header("Location: register_rental.php");
        exit();
    }
}
?>