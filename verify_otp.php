<?php
// verify_otp.php — ยืนยัน OTP
// ------------------------------------------------------------
// โฟลว์รวม 2 แบบ:
// A) ยังไม่เคยยืนยันอีเมล (is_verified=0) → ยืนยันอีเมล + ล้าง OTP + ตั้ง session + ส่ง profile กลับ
// B) เคยยืนยันแล้ว (is_verified=1)        → โฟลว์ลืมรหัสผ่าน: ออก reset_token (ใช้ครั้งเดียว) ส่งกลับให้ FE
// เพิ่มเติม: ใส่ errorCode/ข้อมูลเสริมให้ FE แสดงผลได้แม่นยำ (attemptsLeft, secondsLeft ฯลฯ)
// ------------------------------------------------------------

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

const MAX_ATTEMPT = 5;       // ใส่ผิดได้กี่ครั้งก่อนล็อก
const LOCK_SEC    = 10 * 60; // ล็อก 10 นาที
const TOKEN_TTL   = 15 * 60; // อายุ reset token 15 นาที

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput([
        'success'   => false,
        'message'   => 'Method not allowed',
        'errorCode' => 'METHOD_NOT_ALLOWED'
    ], 405);
}

/* ───── รับค่า ───── */
$email = trim(sanitize($_POST['email'] ?? ''));
$otp   = trim(sanitize($_POST['otp']   ?? ''));

// (ระวัง log OTP ใน production)
if ($email === '' || $otp === '') {
    jsonOutput([
        'success'   => false,
        'message'   => 'กรุณากรอกอีเมลและ OTP',
        'errorCode' => 'BAD_REQUEST'
    ], 400);
}

/* ───── อ่าน record ───── */
$rec = dbOne(
    "SELECT user_id, email, is_verified,
            profile_name, path_imgProfile,
            otp, otp_expires_at, otp_sent_at,
            attempts, lock_until
       FROM user
      WHERE email = ?
      LIMIT 1",
    [$email]
);

if (!$rec) {
    jsonOutput([
        'success'   => false,
        'message'   => 'ยังไม่เคยขอ OTP',
        'errorCode' => 'OTP_NOT_ISSUED'
    ], 404);
}

/* ───── ถูกล็อกอยู่ไหม ───── */
if (!empty($rec['lock_until']) && time() < strtotime($rec['lock_until'])) {
    $wait = max(0, strtotime($rec['lock_until']) - time());
    jsonOutput([
        'success'     => false,
        'message'     => "บัญชีล็อก {$wait} วินาที",
        'errorCode'   => 'ACCOUNT_LOCKED',
        'secondsLeft' => $wait
    ], 423);
}

/* ───── ตรวจ OTP ───── */
$otpIsMatch = hash_equals((string)$rec['otp'], (string)$otp);

// ผิด → เพิ่ม attempts / ถ้าเกินให้ล็อก + แจ้ง 423
if (!$otpIsMatch) {
    $att = (int)$rec['attempts'] + 1;

    if ($att >= MAX_ATTEMPT) {
        $until = date('Y-m-d H:i:s', time() + LOCK_SEC);
        dbExec("UPDATE user SET attempts = 0, lock_until = ? WHERE email = ?", [$until, $email]);

        jsonOutput([
            'success'     => false,
            'message'     => 'ใส่รหัสผิดหลายครั้งเกินกำหนด — บัญชีถูกล็อกชั่วคราว',
            'errorCode'   => 'ACCOUNT_LOCKED',
            'secondsLeft' => LOCK_SEC
        ], 423);
    }

    dbExec("UPDATE user SET attempts = ? WHERE email = ?", [$att, $email]);
    $left = MAX_ATTEMPT - $att;

    jsonOutput([
        'success'      => false,
        'message'      => "OTP ไม่ถูกต้อง (เหลือโอกาสอีก {$left} ครั้ง)",
        'errorCode'    => 'OTP_INCORRECT',
        'attemptsLeft' => $left
    ], 401);
}

/* ───── หมดอายุไหม ───── */
if (!empty($rec['otp_expires_at']) && time() > strtotime($rec['otp_expires_at'])) {
    // ล้างค่า OTP ให้เรียบร้อย
    dbExec("UPDATE user
               SET otp = NULL, otp_expires_at = NULL, otp_sent_at = NULL, attempts = 0
             WHERE email = ?", [$email]);

    jsonOutput([
        'success'   => false,
        'message'   => 'OTP หมดอายุแล้ว',
        'errorCode' => 'OTP_EXPIRED'
    ], 410);
}

/* ───── ถึงตรงนี้ = OTP ถูกต้อง และไม่หมดอายุ ───── */
$now = date('Y-m-d H:i:s');

/* ── A) ยังไม่เคยยืนยันอีเมล ── */
if ((int)$rec['is_verified'] !== 1) {
    dbExec("
        UPDATE user
           SET attempts = 0,
               lock_until = NULL,
               is_verified = 1,
               verified_at = ?,
               otp = NULL, otp_expires_at = NULL, otp_sent_at = NULL
         WHERE email = ?
    ", [$now, $email]);

    $_SESSION['user_id'] = (int)$rec['user_id'];

    jsonOutput([
        'success' => true,
        'message' => 'ยืนยันอีเมลสำเร็จ',
        'flow'    => 'verify',
        'data'    => [
            'user_id'         => (int)$rec['user_id'],
            'email'           => $rec['email'],
            'profile_name'    => (string)($rec['profile_name'] ?? ''),
            'path_imgProfile' => (string)($rec['path_imgProfile'] ?? ''),
        ],
    ]);
    exit;
}

/* ── B) เคยยืนยันแล้ว → โฟลว์ลืมรหัสผ่าน ── */
$expires    = date('Y-m-d H:i:s', time() + TOKEN_TTL);
$resetToken = bin2hex(random_bytes(32));       // token จริง (ส่งให้ FE)
$tokenHash  = hash('sha256', $resetToken);     // เก็บ hash ใน DB

dbExec("
    UPDATE user
       SET attempts = 0,
           lock_until = NULL,
           reset_token_hash = ?,
           reset_token_expires_at = ?,
           otp = NULL, otp_expires_at = NULL, otp_sent_at = NULL
     WHERE email = ?
", [$tokenHash, $expires, $email]);

jsonOutput([
    'success'     => true,
    'message'     => 'OTP ถูกต้อง',
    'flow'        => 'reset',
    'reset_token' => $resetToken,
    'expires_in'  => TOKEN_TTL
]);
