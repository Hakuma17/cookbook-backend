<?php
// verify_otp.php — ยืนยัน OTP แล้วออก Reset Token (สำหรับตั้งรหัสผ่านใหม่)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

const MAX_ATTEMPT = 5;
const LOCK_SEC    = 600;   // 10 นาที
const TOKEN_TTL   = 15 * 60; // อายุ reset token 15 นาที

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('[verify_otp] Method not allowed: ' . $_SERVER['REQUEST_METHOD']);
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

// ⛑ trim + sanitize
$email = trim(sanitize($_POST['email'] ?? ''));
$otp   = trim(sanitize($_POST['otp']   ?? ''));

error_log("[verify_otp] Received email={$email}, otp={$otp}");

if ($email === '' || $otp === '') {
    error_log('[verify_otp] Missing email or otp');
    jsonOutput(['success'=>false,'message'=>'กรุณากรอกอีเมลและ OTP'],400);
}

/* ใช้ตาราง user โดยตรง */
$rec = dbOne(
    "SELECT otp, otp_expires_at, attempts, lock_until
       FROM user
      WHERE email=? LIMIT 1",
    [$email]
);
error_log('[verify_otp] dbOne returned: ' . json_encode($rec));

if (!$rec) {
    error_log("[verify_otp] No OTP record for email={$email}");
    jsonOutput(['success'=>false,'message'=>'ยังไม่เคยขอ OTP'],404);
}

/* ถูกล็อก? */
if (!empty($rec['lock_until']) && time() < strtotime($rec['lock_until'])) {
    $wait = strtotime($rec['lock_until']) - time();
    error_log("[verify_otp] Account locked for {$wait} seconds");
    jsonOutput(['success'=>false,'message'=>"บัญชีล็อก {$wait} วินาที"],423);
}

/* OTP ผิด */
$otpIsMatch = hash_equals((string)$rec['otp'], (string)$otp);
if (!$otpIsMatch) {
    $att = (int)$rec['attempts'] + 1;
    error_log("[verify_otp] Invalid OTP attempt {$att}/" . MAX_ATTEMPT);

    if ($att >= MAX_ATTEMPT) {
        $until = date('Y-m-d H:i:s', time() + LOCK_SEC);
        dbExec("UPDATE user SET attempts=0, lock_until=? WHERE email=?", [$until, $email]);
        error_log("[verify_otp] OTP attempts exceeded. Locked until {$until}");
        jsonOutput(['success'=>false,'message'=>'OTP ผิดเกินกำหนด – ล็อก 10 นาที'],429);
    }

    dbExec("UPDATE user SET attempts=? WHERE email=?", [$att, $email]);
    $left = MAX_ATTEMPT - $att;
    error_log("[verify_otp] OTP incorrect, {$left} attempts left");
    jsonOutput(['success'=>false,'message'=>"OTP ไม่ถูกต้อง (เหลือ {$left} ครั้ง)"],401);
}

/* หมดอายุ? */
if (!empty($rec['otp_expires_at']) && time() > strtotime($rec['otp_expires_at'])) {
    error_log('[verify_otp] OTP expired at ' . $rec['otp_expires_at']);
    dbExec("UPDATE user
               SET otp=NULL, otp_expires_at=NULL, otp_sent_at=NULL, attempts=0
             WHERE email=?", [$email]);
    jsonOutput(['success'=>false,'message'=>'OTP หมดอายุแล้ว'],410);
}

/* ───────── สำเร็จ ─────────
 * - ล้าง OTP เดิม
 * - ออก reset token (ใช้ครั้งเดียว) แล้วเก็บ SHA-256 hash + เวลาหมดอายุ
 * - คืน reset_token ให้แอป (ห้ามเก็บ token จริงใน DB)
 */
$now     = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', time() + TOKEN_TTL);

// โทเคนจริงที่ส่งให้ client
$resetToken = bin2hex(random_bytes(32)); // 64 ตัวอักษร hex
$tokenHash  = hash('sha256', $resetToken);

dbExec("
    UPDATE user
       SET attempts=0,
           lock_until=NULL,
           is_verified=1,
           verified_at=?,
           -- ล้าง OTP
           otp=NULL,
           otp_expires_at=NULL,
           otp_sent_at=NULL,
           -- เก็บ hash ของ reset token + หมดอายุ
           reset_token_hash=?,
           reset_token_expires_at=?
     WHERE email=?",
    [$now, $tokenHash, $expires, $email]
);

error_log("[verify_otp] OTP verified. Issued reset token for {$email}");

$_SESSION['verified_email'] = $email;
$_SESSION['verified_at']    = time();

jsonOutput([
    'success'     => true,
    'message'     => 'OTP ถูกต้อง',
    'reset_token' => $resetToken, // ← ส่งให้แอปไปใช้ที่หน้า new_password
    'expires_in'  => TOKEN_TTL    // วินาที
]);
