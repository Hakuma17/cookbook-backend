<?php
/**
 * upload_profile_image.php — อัปโหลด & รี‑เข้ารหัสรูปโปรไฟล์
 * =====================================================================
 * ขั้นตอน:
 *   1) requireLogin() ผู้ใช้ต้องล็อกอิน
 *   2) รับไฟล์จาก field ที่รองรับ (profile_image | image | avatar)
 *   3) ตรวจขนาด (≤ 5MB), ตรวจชนิดไฟล์ (นามสกุล + MIME + getimagesize)
 *   4) รี‑เข้ารหัสเป็น JPEG เสมอ (quality 82) + ลดขนาดไม่เกิน 1024px ด้านยาวสุด → ลบ metadata
 *   5) เซฟชื่อรูปแบบ user_{id}.jpg (ทับของเดิม / ลบของเก่าทุกรูปก่อน)
 *   6) อัปเดต path ในตาราง user (path_imgProfile)
 *   7) ส่งคืน URL พร้อมตัวแปร ?t=timestamp กัน cache เก่า
 * หมายเหตุความปลอดภัย:
 *   - จำกัดชนิดให้แคบ (jpg/png/webp) แล้วรี‑encode → กัน payload อันตรายฝัง EXIF/script
 *   - ใช้ getimagesize เพิ่มชั้นตรวจ (ไฟล์ปลอมที่ต่อ header จะถูกตัด)
 *   - ไม่ยอมรับ SVG / GIF (ลดความเสี่ยง XSS/large memory)
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // รับเฉพาะ POST
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

$uid = requireLogin();

/* ── 1) ค้นหาไฟล์ที่อัปโหลด ─────────────────────── */
// Accept common field names: profile_image, image, avatar
$fileField  = null;
foreach (['profile_image','image','avatar'] as $key) {
    if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) { $fileField = $key; break; }
}
if ($fileField === null) {
    jsonOutput(['success'=>false,'message'=>'ไม่พบไฟล์รูปภาพ'],400);
}

$f       = $_FILES[$fileField];

// 2) ตรวจขนาด ≤ 5MB
if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
    jsonOutput(['success'=>false,'message'=>'ไฟล์ใหญ่เกินไป (สูงสุด 5MB)'],413);
}
// 3) หา extension จากชื่อไฟล์ (fallback เป็น MIME)
$ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp'];
if ($ext === '') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']) ?: '';
    $map   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $ext   = $map[$mime] ?? '';
}

if (!in_array($ext, $allowed, true)) {
    jsonOutput(['success'=>false,'message'=>'รองรับ jpg, jpeg, png, webp เท่านั้น'],400);
}
// 4) ตรวจ MIME จริง + getimagesize กันปลอม
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']) ?: '';
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
    jsonOutput(['success'=>false,'message'=>'ไฟล์ไม่ใช่รูปภาพ'],400);
}
if (!getimagesize($f['tmp_name'])) {
    jsonOutput(['success'=>false,'message'=>'ไฟล์ไม่ใช่รูปภาพ'],400);
}

/* ── 5) เตรียมโฟลเดอร์ & รี‑encode ─────────────────────── */
$dir = __DIR__ . '/uploads/users';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    jsonOutput(['success'=>false,'message'=>'สร้างโฟลเดอร์ไม่สำเร็จ'],500);
}

// Always re-encode to JPEG to strip metadata and unify format
$name = "user_{$uid}.jpg";
$path = "{$dir}/{$name}";
$rel  = "uploads/users/{$name}";

// ลบไฟล์เก่าของ user เดียวกัน (ทุกนามสกุลที่รองรับ)
foreach (glob("{$dir}/user_{$uid}.*") as $old) {
    @unlink($old);
}

// ใช้ GD resize + บันทึกเป็น JPEG คุณภาพ 82
$srcImg = null;
switch ($mime) {
    case 'image/jpeg': $srcImg = @imagecreatefromjpeg($f['tmp_name']); break;
    case 'image/png':  $srcImg = @imagecreatefrompng($f['tmp_name']);  break;
    case 'image/webp': $srcImg = @imagecreatefromwebp($f['tmp_name']); break;
}
if (!$srcImg) {
    jsonOutput(['success'=>false,'message'=>'ไม่สามารถอ่านรูปภาพ'],400);
}
$w = imagesx($srcImg); $h = imagesy($srcImg);
$max = 1024; $nw = $w; $nh = $h;
if ($w > $max || $h > $max) {
    if ($w >= $h) { $nw = $max; $nh = (int)round($h * ($max / $w)); }
    else { $nh = $max; $nw = (int)round($w * ($max / $h)); }
}
$dst = imagecreatetruecolor($nw, $nh);
imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
@imagejpeg($dst, $path, 82);
imagedestroy($srcImg); imagedestroy($dst);
if (!is_file($path)) {
    jsonOutput(['success'=>false,'message'=>'บันทึกรูปไม่สำเร็จ'],500);
}

/* ── 6) อัปเดตฐานข้อมูล + ส่งผลลัพธ์ ───────────────────── */
try {
    dbExec("UPDATE user SET path_imgProfile = ? WHERE user_id = ?", [$rel, $uid]);

    $url = getBaseUrl() . '/' . $rel;
    $urlWithCacheBust = $url . '?t=' . time(); // ป้องกัน cache เก่า

    jsonOutput([
        'success' => true,
        'data' => [
            'image_url'     => $urlWithCacheBust,
            'relative_path' => $rel
        ]
    ]);
} catch (Throwable $e) {
    error_log('[upload_profile_image] ' . $e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
