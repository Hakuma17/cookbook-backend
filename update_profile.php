<?php
// update_profile.php — อัปเดตชื่อผู้ใช้ + path รูปโปรไฟล์

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid = requireLogin();

/* ──────────── รับข้อมูล JSON ──────────── */
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name = sanitize($body['profile_name'] ?? '');
$path = trim($body['profile_image'] ?? '');

/* ──────────── ตรวจสอบชื่อ ──────────── */
if ($name === '') {
    jsonOutput(['success' => false, 'message' => 'กรุณาระบุชื่อผู้ใช้'], 400);
}

/* ──────────── ตรวจสอบ path รูป ──────────── */
if ($path !== '') {
    if (!preg_match('#^uploads/users/[^/]+\.(jpg|jpeg|png)$#i', $path)) {
        jsonOutput(['success' => false, 'message' => 'path รูปไม่ถูกต้อง'], 400);
    }
}

try {
    /* ──────────── บันทึกข้อมูล ──────────── */
    dbExec("
        UPDATE user
           SET profile_name = ?, path_imgProfile = ?
         WHERE user_id = ?
    ", [$name, $path, $uid]);

    /* ──────────── เตรียม URL รูป ──────────── */
    $url = getBaseUrl() . '/' . (
        $path !== '' ? ltrim($path, '/') : 'uploads/users/default_avatar.png'
    );

    jsonOutput(['success' => true, 'data' => [
        'profile_name' => $name,
        'image_url'    => $url,
    ]]);

} catch (Throwable $e) {
    error_log('[update_profile] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
