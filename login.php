<?php // login.php — เข้าสู่ระบบด้วยอีเมล/รหัสผ่าน + ตรวจยืนยันอีเมล

require_once __DIR__ . '/inc/config.php';     // โหลดการตั้งค่า (DB, error policy)
require_once __DIR__ . '/inc/functions.php';  // รวม helper (respond, sanitize, session start)
require_once __DIR__ . '/inc/db.php';         // ฟังก์ชัน dbOne / dbVal ฯลฯ

// รับเฉพาะ POST เพื่อความปลอดภัย (หลีกเลี่ยง query string log)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

// ตัดช่องว่างเผื่อผู้ใช้เผลอเว้น
$email = trim(sanitize($_POST['email'] ?? ''));
$pass  = $_POST['password'] ?? '';

// ตรวจความครบถ้วน
if ($email === '' || $pass === '') {
    respond(false, ['message' => 'กรุณาระบุอีเมลและรหัสผ่าน'], 400);
}

try {
    // ดึงข้อมูลผู้ใช้ (รหัสผ่าน + สถานะยืนยัน)
    $row = dbOne('SELECT user_id, password, is_verified FROM user WHERE email = ? LIMIT 1', [$email]);

    if (!$row) { // ไม่พบอีเมล
        respond(false, ['message' => 'ไม่พบบัญชีนี้'], 200);
    }

    // ถ้ายังไม่ verify อีเมล → ให้ FE รู้เพื่องาน OTP ต่อ
    if ((int)$row['is_verified'] !== 1) {
        jsonOutput([
            'success'     => false,
            'message'     => 'กรุณายืนยันอีเมลก่อนเข้าสู่ระบบ',
            'errorCode'   => 'EMAIL_NOT_VERIFIED',
            'must_verify' => true
        ], 403);
    }

    // ตรวจรหัสผ่าน
    if (!password_verify($pass, $row['password'] ?? '')) {
        respond(false, ['message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 200);
    }

    // ผ่าน → ตั้ง session (ป้องกัน fixation ด้วย regenerate_id)
    $uid = (int)$row['user_id'];
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $uid;

    // ดึงข้อมูลโปรไฟล์เพิ่มเติม (ชื่อ + รูป) เพื่อให้ FE แคชได้เลย
    $info = dbOne('SELECT profile_name, path_imgProfile FROM user WHERE user_id = ?', [$uid]) ?: [];

    respond(true, [
        'user_id'         => $uid,
        'email'           => $email,
        'profile_name'    => $info['profile_name']    ?? '',
        'path_imgProfile' => $info['path_imgProfile'] ?? ''
    ]);

} catch (Throwable $e) {
    error_log('[login] ' . $e->getMessage()); // เก็บ log ฝั่งเซิร์ฟเวอร์
    respond(false, ['message' => 'Server error'], 500); // ตอบ generic message เพื่อความปลอดภัย
}
