<?php
// update_profile.php — อัปเดตชื่อผู้ใช้ + path รูปโปรไฟล์

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

$uid = requireLogin();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name = sanitize($body['profile_name'] ?? '');
$path = trim($body['profile_image'] ?? '');

if ($name === '') {
    jsonOutput(['success'=>false,'message'=>'กรุณาระบุชื่อผู้ใช้'],400);
}

try {
    dbExec("
        UPDATE user SET profile_name = ?, path_imgProfile = ? WHERE user_id = ?
    ", [$name, $path, $uid]);

    $url = getBaseUrl() . '/' . ($path !== '' ? ltrim($path, '/') : 'uploads/users/default_avatar.png');

    jsonOutput(['success'=>true,'data'=>[
        'profile_name'=>$name,
        'image_url'   =>$url,
    ]]);
} catch (Throwable $e) {
    error_log('[update_profile] '.$e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
