<?php
// upload_profile_image.php — รับไฟล์รูป, เซฟ, อัปเดต DB

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

$uid = requireLogin();

/* ── ตรวจไฟล์ ─────────────────────── */
if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    jsonOutput(['success'=>false,'message'=>'ไม่พบไฟล์รูปภาพ'],400);
}

$f       = $_FILES['profile_image'];
$ext     = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','webp'];

if (!in_array($ext,$allowed,true)) {
    jsonOutput(['success'=>false,'message'=>'รองรับ jpg,jpeg,png,webp เท่านั้น'],400);
}
if (!getimagesize($f['tmp_name'])) {
    jsonOutput(['success'=>false,'message'=>'ไฟล์ไม่ใช่รูปภาพ'],400);
}

/* ── เซฟไฟล์ ─────────────────────── */
$dir   = __DIR__.'/uploads/users';
$name  = "user_{$uid}.{$ext}";
$path  = "{$dir}/{$name}";
$rel   = "uploads/users/{$name}";

if (!is_dir($dir) && !mkdir($dir,0755,true)) {
    jsonOutput(['success'=>false,'message'=>'สร้างโฟลเดอร์ไม่สำเร็จ'],500);
}
@unlink($path);                                // ลบไฟล์เก่า (ถ้ามี)

if (!move_uploaded_file($f['tmp_name'],$path)) {
    jsonOutput(['success'=>false,'message'=>'ย้ายไฟล์ไม่สำเร็จ'],500);
}

/* ── อัปเดต DB ───────────────────── */
try {
    dbExec("UPDATE user SET path_imgProfile=? WHERE user_id=?", [$rel, $uid]);

    jsonOutput(['success'=>true,'data'=>[
        'image_url'     => getBaseUrl().'/'.$rel,
        'relative_path' => $rel,
    ]]);
} catch (Throwable $e) {
    error_log('[upload_profile_image] '.$e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
