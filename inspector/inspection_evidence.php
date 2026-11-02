<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
include "includes/auth.php"; 
include "../config/db.php"; 
include "includes/inspector_features.php"; 
date_default_timezone_set('Asia/Manila');

$inspectorId = (int) ($_SESSION['user_id'] ?? 0); 
$id = (int) ($_GET['id'] ?? $_POST['inspection_id'] ?? 0); 
$inspection = inspector_get_owned_inspection($conn, $id, $inspectorId); 

if (!$id || !$inspection) {
    exit('Inspection not found.');
}

$msg = ''; 
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && inspector_check_csrf($_POST['csrf'] ?? '')) {
    if (!empty($_FILES['evidence']['name']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['evidence'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        
        if (!in_array($ext, $allowed, true)) {
            $err = 'Only JPG, PNG, WEBP, and PDF files are allowed.';
        } elseif (!upload_content_ok($file['tmp_name'], $ext)) {
            $err = 'File content does not match its type.';
        } elseif ($file['size'] > 8 * 1024 * 1024) {
            $err = 'Maximum file size is 8 MB.';
        } else {
            $dir = __DIR__ . '/uploads/inspection_evidence';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $safe = 'INS-' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $path = $dir . '/' . $safe;
            
            if (move_uploaded_file($file['tmp_name'], $path)) {
                $rel = 'uploads/inspection_evidence/' . $safe;
                $caption = trim($_POST['caption'] ?? '');
                $name = $file['name'];
                $uid = (int) ($_SESSION['user_id'] ?? 0);

                try {
                    $insStmt = $conn->prepare("INSERT INTO inspector_evidence (inspection_id, file_name, file_path, caption, uploaded_by, created_at) VALUES (:inspection_id, :file_name, :file_path, :caption, :uploaded_by, NOW())");
                    $insStmt->execute([
                        ':inspection_id' => $id,
                        ':file_name'     => $name,
                        ':file_path'     => $rel,
                        ':caption'       => $caption,
                        ':uploaded_by'   => $uid
                    ]);
                    $msg = 'Evidence uploaded successfully.';
                } catch (PDOException $e) {
                    $err = 'Unable to save evidence record to database.';
                }
            } else {
                $err = 'Unable to save the uploaded file.';
            }
        }
    }
}

// Delete Evidence (PDO Safe)
if (isset($_GET['delete'])) {
    $eid = (int) $_GET['delete'];
    try {
        $selStmt = $conn->prepare("SELECT * FROM inspector_evidence WHERE id = :eid AND inspection_id = :inspection_id LIMIT 1");
        $selStmt->execute([':eid' => $eid, ':inspection_id' => $id]);
        $r = $selStmt->fetch(PDO::FETCH_ASSOC);

        if ($r) {
            $file = __DIR__ . '/' . $r['file_path'];
            if (is_file($file)) {
                @unlink($file);
            }
            $delStmt = $conn->prepare("DELETE FROM inspector_evidence WHERE id = :eid");
            $delStmt->execute([':eid' => $eid]);
            $msg = 'Evidence removed.';
        }
    } catch (PDOException $e) {
        $err = 'Failed to remove evidence.';
    }
}

// Fetch Rows (PDO Safe)
$rows = [];
try {
    $rowStmt = $conn->prepare("SELECT * FROM inspector_evidence WHERE inspection_id = :inspection_id ORDER BY created_at DESC");
    $rowStmt->execute([':inspection_id' => $id]);
    $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inspection Evidence</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="assets/css/inspector.css"></head><body><div class="wrapper"><?php include 'includes/sidebar.php';?><div class="main"><?php include 'includes/navbar.php';?><div class="container-fluid p-4"><div class="page-title"><h2><i class="fa-solid fa-camera" style="color:#ff7a00"></i> Inspection Evidence</h2><p><?php echo inspector_e($inspection['rental_name']??$inspection['business_name']??'Property'); ?> · upload photos and supporting documents.</p></div><?php if($msg)echo inspector_flash('success',$msg);if($err)echo inspector_flash('danger',$err);?><div class="card shadow-sm border-0 rounded-4 mb-4"><div class="card-body"><form method="post" enctype="multipart/form-data" class="row g-3 align-items-end"><input type="hidden" name="csrf" value="<?php echo inspector_csrf();?>"><input type="hidden" name="inspection_id" value="<?php echo $id;?>"><div class="col-md-5"><label class="form-label fw-semibold">Evidence File</label><input type="file" name="evidence" class="form-control" accept="image/*,.pdf" required></div><div class="col-md-5"><label class="form-label fw-semibold">Caption / Description</label><input name="caption" class="form-control" placeholder="e.g. Front facade damage"></div><div class="col-md-2"><button class="btn btn-warning w-100"><i class="fa-solid fa-upload me-1"></i> Upload</button></div></form></div></div><div class="row g-3"><?php if(!$rows):?><div class="col-12"><div class="card border-0 shadow-sm rounded-4"><div class="card-body text-center text-muted py-5"><i class="fa-regular fa-images fa-3x mb-3"></i><p>No evidence uploaded yet.</p></div></div></div><?php else:foreach($rows as $r):$isImg=in_array(strtolower(pathinfo($r['file_path'],PATHINFO_EXTENSION)),['jpg','jpeg','png','webp'],true);?><div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100"><?php if($isImg):?><img src="<?php echo inspector_e($r['file_path']);?>" class="w-100" style="height:210px;object-fit:cover" alt="Evidence"><?php else:?><div class="d-flex align-items-center justify-content-center bg-light" style="height:210px"><i class="fa-solid fa-file-pdf fa-4x text-danger"></i></div><?php endif;?><div class="card-body"><strong><?php echo inspector_e($r['caption']?:$r['file_name']);?></strong><small class="d-block text-muted mt-1"><?php echo date('M d, Y h:i A',strtotime($r['created_at']));?></small><div class="mt-3"><a class="btn btn-sm btn-outline-primary" href="<?php echo inspector_e($r['file_path']);?>" target="_blank"><i class="fa-solid fa-eye"></i> View</a><a class="btn btn-sm btn-outline-danger ms-1" href="?id=<?php echo $id;?>&delete=<?php echo (int)$r['id'];?>" onclick="return confirm('Delete this evidence?')"><i class="fa-solid fa-trash"></i></a></div></div></div></div><?php endforeach;endif;?></div><a href="inspection_form.php?id=<?php echo $id;?>" class="btn btn-outline-secondary mt-4">← Back to Inspection Form</a></div><?php include 'includes/footer.php';?></div></div></body></html>