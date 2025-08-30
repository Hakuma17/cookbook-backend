<?php
// update_profile.php — อัปเดตชื่อ / รูป / ข้อมูลใต้โปรไฟล์ (อัปเดตเฉพาะคีย์ที่ส่งมา)
require_once __DIR__ . '/inc/config.php';
// ★ แก้ลำดับ: functions ก่อน db.php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid = requireLogin();

function utf8_bytes_len(string $s): int { return strlen($s); }

/**
 * คืนค่า path ใต้ uploads/users/... ให้เป็นรูปแบบมาตรฐาน
 * - รับได้ทั้ง URL เต็มหรือ path ดิบ
 * - ตัด query string ออก
 * - แทน \ เป็น /
 * - คืนค่าว่าง "" ถ้าให้ลบรูป
 */
function normalize_upload_user_path(?string $raw): string {
    $raw = trim((string)($raw ?? ''));
    if ($raw === '') return '';
    if (($qPos = strpos($raw, '?')) !== false) $raw = substr($raw, 0, $qPos);
    $raw = str_replace('\\', '/', $raw);
    if (stripos($raw, 'http://') === 0 || stripos($raw, 'https://') === 0) {
        $p = parse_url($raw, PHP_URL_PATH) ?? '';
        $raw = $p;
    }
    if (preg_match('#/uploads/users/[^/]+\.(jpg|jpeg|png)$#i', $raw, $m)) {
        $raw = $m[0];
    }
    return ltrim($raw, '/');
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$hasName  = array_key_exists('profile_name', $body) || array_key_exists('profileName', $body);
$hasImage = array_key_exists('profile_image', $body) || array_key_exists('image_url', $body) || array_key_exists('imagePath', $body);
$hasInfo  = array_key_exists('profile_info', $body) || array_key_exists('profileInfo', $body);

$name   = $hasName  ? sanitize($body['profile_name'] ?? $body['profileName'] ?? '') : null;
$pathRaw= $hasImage ? ($body['profile_image'] ?? $body['image_url'] ?? $body['imagePath'] ?? '') : null;
$info   = $hasInfo  ? sanitize($body['profile_info'] ?? $body['profileInfo'] ?? '') : null;

$path = $hasImage ? normalize_upload_user_path($pathRaw) : null;

if ($hasName) {
    if ($name === '') jsonOutput(['success' => false, 'message' => 'กรุณาระบุชื่อผู้ใช้'], 400);
    $NAME_DB_MAX_BYTES = 100;
    if (utf8_bytes_len($name) > $NAME_DB_MAX_BYTES) {
        jsonOutput(['success' => false, 'message' => 'ชื่อต้องมีความยาวไม่เกิน '.$NAME_DB_MAX_BYTES.' ไบต์'], 400);
    }
}
if ($hasImage) {
    if ($path !== '' && !preg_match('#^uploads/users/[^/]+\.(jpg|jpeg|png)$#i', $path)) {
        jsonOutput(['success' => false, 'message' => 'path รูปไม่ถูกต้อง'], 400);
    }
}
if ($hasInfo) {
    $INFO_DB_MAX_BYTES = 1000;
    if ($info !== '' && utf8_bytes_len($info) > $INFO_DB_MAX_BYTES) {
        jsonOutput(['success' => false, 'message' => 'ข้อมูลใต้โปรไฟล์ยาวเกินไป (ไม่เกิน '.$INFO_DB_MAX_BYTES.' ไบต์)'], 400);
    }
}
if (!$hasName && !$hasImage && !$hasInfo) {
    jsonOutput(['success' => false, 'message' => 'ไม่มีข้อมูลสำหรับอัปเดต'], 400);
}

$sets = [];
$params = [];
if ($hasName)  { $sets[] = 'profile_name = ?';    $params[] = $name; }
if ($hasImage) { $sets[] = 'path_imgProfile = ?'; $params[] = $path ?? ''; }
if ($hasInfo)  { $sets[] = 'profile_info = ?';    $params[] = $info ?? ''; }
$params[] = $uid;

$sql = 'UPDATE user SET '.implode(', ', $sets).' WHERE user_id = ?';

try {
    dbExec($sql, $params);

    // ★ ใช้ dbRow() จาก inc/db.php (ไม่มี undefined แล้ว)
    $u = dbRow("
        SELECT profile_name, profile_info, path_imgProfile, email
          FROM user
         WHERE user_id = ?
         LIMIT 1
    ", [$uid]);

    $pathNow = trim((string)($u['path_imgProfile'] ?? ''));
    if (($qPos = strpos($pathNow, '?')) !== false) $pathNow = substr($pathNow, 0, $qPos);
    $pathNow = str_replace('\\', '/', $pathNow);
    if ($pathNow === '') $pathNow = 'uploads/users/default_avatar.png';

    $base = rtrim(getBaseUrl(), '/');
    $imageUrl = $base.'/'.ltrim($pathNow, '/');

    jsonOutput(['success' => true, 'data' => [
        'profile_name'    => (string)($u['profile_name'] ?? ''),
        'profile_info'    => (string)($u['profile_info'] ?? ''),
        'path_imgProfile' => $pathNow,
        'image_url'       => $imageUrl,
        'email'           => (string)($u['email'] ?? ''),
    ]]);

} catch (Throwable $e) {
    error_log('[update_profile] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
