<?php
// new_password.php — รีเซ็ตรหัสผ่านด้วย OTP (Refactor)

require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/functions.php';   // ⟵ รวม jsonOutput
require_once __DIR__.'/inc/db.php';          // ⟵ รวม dbOne / dbExec

// ─── 1) Method guard ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

// ─── 2) รับ & validate input ─────────────────────────
$email = sanitize($_POST['email']        ?? '');
$otp   = sanitize($_POST['otp']          ?? '');
$pass  =            $_POST['new_password'] ?? '';   // ไม่ต้อง sanitize hash

if ($email==='' || $otp==='' || $pass==='') {
    jsonOutput(['success'=>false,'message'=>'กรุณากรอกข้อมูลให้ครบถ้วน'],400);
}

try {
    /* ───────────────────────────────────────────────
     * 3) ตรวจ OTP  (dbOne จะคืน array|false)
     * ───────────────────────────────────────────── */
    $row = dbOne(
        "SELECT otp, otp_expires_at
           FROM user_otp
          WHERE email = ?
          LIMIT 1", [$email]
    );

    $otpExpired = $row && strtotime($row['otp_expires_at']) < time();
    if (!$row || $row['otp'] !== $otp || $otpExpired) {
        jsonOutput(['success'=>false,'message'=>'OTP ไม่ถูกต้องหรือหมดอายุ'],401);
    }

    /* ───────────────────────────────────────────────
     * 4) อัปเดตรหัสผ่าน + ลบ OTP (transaction เล็ก ๆ)
     * ───────────────────────────────────────────── */
    dbExec("BEGIN");

    $hash = password_hash($pass, PASSWORD_ARGON2ID);
    dbExec("UPDATE user SET password = ? WHERE email = ?", [$hash,$email]);
    dbExec("DELETE FROM user_otp WHERE email = ?",          [$email]);

    dbExec("COMMIT");

    /* ───────────────────────────────────────────────
     * 5) ดึงข้อมูลโปรไฟล์ตอบกลับ
     * ───────────────────────────────────────────── */
    $info = dbOne(
        "SELECT profile_name, path_imgProfile
           FROM user
          WHERE email = ?
          LIMIT 1", [$email]
    ) ?: [];

    jsonOutput([
        'success'=>true,
        'message'=>'เปลี่ยนรหัสผ่านสำเร็จ',
        'data'=>[
            'profile_name'    => $info['profile_name']    ?? '',
            'path_imgProfile' => $info['path_imgProfile'] ?? ''
        ]
    ]);

} catch (Throwable $e) {
    // ถ้ามี transaction เปิดอยู่ → rollback ปลอดภัย
    try { dbExec("ROLLBACK"); } catch(Throwable $x) {}
    error_log('[new_password] '.$e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
