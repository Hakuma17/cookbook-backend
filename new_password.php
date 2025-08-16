<?php
// new_password.php — ตั้งรหัสผ่านด้วย reset_token (ทางเลือก B)

require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/functions.php';   // jsonOutput
require_once __DIR__.'/inc/db.php';          // dbOne / dbExec

// 1) Method guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

// 2) รับค่า
$email      = trim(sanitize($_POST['email']        ?? ''));
$resetToken = trim($_POST['reset_token']           ?? ''); // token ไม่ sanitize แค่ trim
$pass       =         $_POST['new_password']       ?? '';

if ($email==='' || $resetToken==='' || $pass==='') {
    jsonOutput(['success'=>false,'message'=>'กรุณากรอกข้อมูลให้ครบถ้วน'],400);
}

try {
    // 3) ดึง hash และหมดอายุของ token
    $row = dbOne("
        SELECT reset_token_hash, reset_token_expires_at
          FROM user
         WHERE email = ?
         LIMIT 1
    ", [$email]);

    if (!$row || empty($row['reset_token_hash']) || empty($row['reset_token_expires_at'])) {
        jsonOutput(['success'=>false,'message'=>'โทเคนไม่ถูกต้อง'],401);
    }

    // 4) ตรวจหมดอายุ
    if (time() > strtotime($row['reset_token_expires_at'])) {
        // ล้าง token ที่หมดอายุทิ้ง
        dbExec("UPDATE user
                   SET reset_token_hash=NULL, reset_token_expires_at=NULL
                 WHERE email=?", [$email]);
        jsonOutput(['success'=>false,'message'=>'โทเคนหมดอายุแล้ว'],410);
    }

    // 5) เปรียบเทียบ hash แบบคงที่เวลา
    $calc = hash('sha256', $resetToken);
    if (!hash_equals($row['reset_token_hash'], $calc)) {
        jsonOutput(['success'=>false,'message'=>'โทเคนไม่ถูกต้อง'],401);
    }

    // 6) อัปเดตรหัสผ่าน (transaction)
    dbExec("BEGIN");

    // ใช้ Argon2id ถ้ามีให้; fallback ไป BCRYPT
    if (defined('PASSWORD_ARGON2ID')) {
        $hash = password_hash($pass, PASSWORD_ARGON2ID);
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
    }

    dbExec("
        UPDATE user SET
            password = ?,
            -- ล้าง token เมื่อใช้เสร็จ
            reset_token_hash = NULL,
            reset_token_expires_at = NULL,
            -- เคลียร์สถานะล็อก/นับความพยายาม
            attempts = 0,
            lock_until = NULL
        WHERE email = ?
    ", [$hash, $email]);

    dbExec("COMMIT");

    // 7) ตอบกลับข้อมูลโปรไฟล์เล็กน้อย
    $info = dbOne("
        SELECT profile_name, path_imgProfile
          FROM user
         WHERE email = ?
         LIMIT 1
    ", [$email]) ?: [];

    jsonOutput([
        'success'=>true,
        'message'=>'เปลี่ยนรหัสผ่านสำเร็จ',
        'data'=>[
            'profile_name'    => $info['profile_name']    ?? '',
            'path_imgProfile' => $info['path_imgProfile'] ?? ''
        ]
    ]);

} catch (Throwable $e) {
    try { dbExec("ROLLBACK"); } catch(Throwable $x) {}
    error_log('[new_password] '.$e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
