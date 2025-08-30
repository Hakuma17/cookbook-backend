<?php
// verify_otp.php — ยืนยัน OTP
// ------------------------------------------------------------
// ฟลว์:
// A) ยังไม่เคยยืนยันอีเมล (is_verified = 0)
//    → ยืนยันอีเมล + เคลียร์ OTP/attempts/lock + ตั้ง session + ส่งโปรไฟล์กลับ
// B) เคยยืนยันแล้ว (is_verified = 1)
//    → โฟลว์ลืมรหัสผ่าน: ออก reset_token (ส่ง token จริงกลับ FE, เก็บ hash ใน DB)
// ความปลอดภัย:
// - ล็อกชั่วคราวเมื่อใส่ผิดถึง MAX_ATTEMPT
// - เคลียร์ OTP เมื่อหมดอายุหรือเมื่อตรวจผ่าน
// - เปรียบเทียบด้วย hash_equals
// - ค่าควบคุมอ่านจาก .env (มีค่าเริ่มต้นหากไม่ตั้ง)
// ------------------------------------------------------------

// *** สำคัญ: ไม่มีเอาต์พุตใด ๆ ก่อนบรรทัดนี้ และไฟล์ต้องบันทึกเป็น UTF-8 (no BOM) ***

// โหลด autoload + .env
require_once __DIR__ . '/bootstrap.php';

// helper I/O + DB (มี jsonOutput(), sanitize(), dbOne(), dbExec() ฯลฯ)
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

// ----- ค่าควบคุมจาก ENV (มีค่าเริ่มต้นให้) -----
$MAX_ATTEMPT = (int)(getenv('OTP_MAX_ATTEMPT') ?: 5);     // ครั้งที่อนุญาตให้พลาดก่อนล็อก
$LOCK_SEC    = (int)(getenv('OTP_LOCK_SEC')     ?: 600);  // ระยะเวลาล็อก (วินาที) — ดีฟอลต์ 10 นาที
$TOKEN_TTL   = (int)(getenv('TOKEN_TTL')        ?: 900);  // อายุ reset_token (วินาที) — ดีฟอลต์ 15 นาที
$OTP_LEN     = (int)(getenv('OTP_LEN')          ?: 6);    // ความยาว OTP (ใช้ตรวจรูปแบบฝั่งรับ)

// ปัดค่าให้มีเหตุผล (กันตั้งผิด)
if ($MAX_ATTEMPT < 1) $MAX_ATTEMPT = 5;
if ($LOCK_SEC    < 60) $LOCK_SEC    = 600;
if ($TOKEN_TTL   < 60) $TOKEN_TTL   = 900;
if ($OTP_LEN     < 4)  $OTP_LEN     = 6;

// อนุญาตเฉพาะ POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput([
        'success'   => false,
        'message'   => 'วิธีเรียกไม่ถูกต้อง',
        'errorCode' => 'METHOD_NOT_ALLOWED',
    ], 405);
}

// ───────────────── รับค่า ─────────────────
$email = strtolower(trim(sanitize($_POST['email'] ?? '')));
$otp   = trim(sanitize($_POST['otp']   ?? ''));

// ตรวจความถูกต้องเบื้องต้น
if ($email === '' || $otp === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput([
        'success'   => false,
        'message'   => 'กรุณากรอกอีเมลและรหัส OTP ให้ครบถ้วน',
        'errorCode' => 'BAD_REQUEST',
    ], 400);
}

// (ออปชัน) บังคับรูปแบบ OTP ให้เป็นตัวเลขตามความยาวที่กำหนด
if ($OTP_LEN >= 4 && $OTP_LEN <= 10 && !preg_match('/^\d{'.$OTP_LEN.'}$/', $otp)) {
    jsonOutput([
        'success'   => false,
        'message'   => 'รูปแบบรหัส OTP ไม่ถูกต้อง',
        'errorCode' => 'OTP_BAD_FORMAT',
    ], 400);
}

// ───────────────── อ่านเรคคอร์ดผู้ใช้ ─────────────────
$rec = dbOne("
    SELECT user_id, email, is_verified,
           profile_name, path_imgProfile,
           otp, otp_expires_at, otp_sent_at,
           attempts, lock_until
      FROM user
     WHERE email = ?
     LIMIT 1
", [$email]);

if (!$rec) {
    // ไม่บอกว่า “มี/ไม่มีผู้ใช้” ชัด ๆ แต่ในที่นี้ต้องมีเรคคอร์ดเพื่อยืนยัน OTP
    jsonOutput([
        'success'   => false,
        'message'   => 'ยังไม่เคยขอรหัส OTP สำหรับอีเมลนี้',
        'errorCode' => 'OTP_NOT_ISSUED',
    ], 404);
}

// ───────────────── ตรวจสถานะล็อก ─────────────────
if (!empty($rec['lock_until']) && time() < strtotime($rec['lock_until'])) {
    $wait = max(0, strtotime($rec['lock_until']) - time());
    jsonOutput([
        'success'     => false,
        'message'     => "บัญชีถูกล็อกชั่วคราว กรุณารออีก {$wait} วินาที",
        'errorCode'   => 'ACCOUNT_LOCKED',
        'secondsLeft' => $wait,
    ], 423);
}

// ───────────────── โค้ดออกให้หรือยัง/หมดอายุไหม ─────────────────
if (empty($rec['otp']) || empty($rec['otp_expires_at'])) {
    jsonOutput([
        'success'   => false,
        'message'   => 'ยังไม่เคยขอรหัส OTP สำหรับอีเมลนี้',
        'errorCode' => 'OTP_NOT_ISSUED',
    ], 404);
}

if (time() > strtotime($rec['otp_expires_at'])) {
    // เคลียร์ OTP/attempts เมื่อหมดอายุ (กันเดารหัสต่อ)
    dbExec("
        UPDATE user
           SET otp = NULL, otp_expires_at = NULL, otp_sent_at = NULL, attempts = 0
         WHERE email = ?
    ", [$email]);

    jsonOutput([
        'success'   => false,
        'message'   => 'รหัส OTP หมดอายุแล้ว',
        'errorCode' => 'OTP_EXPIRED',
    ], 410);
}

// ───────────────── ตรวจรหัส OTP ─────────────────
$otpIsMatch = hash_equals((string)$rec['otp'], (string)$otp);

if (!$otpIsMatch) {
    $att = (int)$rec['attempts'] + 1;

    // แตะเพดาน → ล็อก + เคลียร์รหัส
    if ($att >= $MAX_ATTEMPT) {
        $until = date('Y-m-d H:i:s', time() + $LOCK_SEC);
        dbExec("
            UPDATE user
               SET attempts = 0,
                   lock_until = ?,
                   otp = NULL, otp_expires_at = NULL, otp_sent_at = NULL
             WHERE email = ?
        ", [$until, $email]);

        jsonOutput([
            'success'     => false,
            'message'     => 'ใส่รหัสผิดหลายครั้งเกินกำหนด — บัญชีถูกล็อกชั่วคราว',
            'errorCode'   => 'ACCOUNT_LOCKED',
            'secondsLeft' => $LOCK_SEC,
        ], 423);
    }

    // ยังไม่ถึงเพดาน → เพิ่มตัวนับ
    dbExec("UPDATE user SET attempts = ? WHERE email = ?", [$att, $email]);
    $left = max(0, $MAX_ATTEMPT - $att);

    jsonOutput([
        'success'      => false,
        'message'      => "รหัส OTP ไม่ถูกต้อง (เหลือโอกาสอีก {$left} ครั้ง)",
        'errorCode'    => 'OTP_INCORRECT',
        'attemptsLeft' => $left,
    ], 401);
}

// ───────────────── ถึงตรงนี้ = OTP ถูกต้อง ─────────────────
$now = date('Y-m-d H:i:s');

// ── A) ยืนยันอีเมล (ยังไม่ verified) ──
if ((int)$rec['is_verified'] !== 1) {
    dbExec("
        UPDATE user
           SET attempts       = 0,
               lock_until     = NULL,
               is_verified    = 1,
               verified_at    = ?,
               otp            = NULL,
               otp_expires_at = NULL,
               otp_sent_at    = NULL
         WHERE email = ?
    ", [$now, $email]);

    // เริ่ม session เพื่อให้เข้าสู่ระบบได้ทันที (ทำ *ก่อน* ส่ง JSON)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // ลดความเสี่ยง session fixation
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int)$rec['user_id'];

    jsonOutput([
        'success' => true,
        'message' => 'ยืนยันอีเมลสำเร็จ',
        'flow'    => 'verify', // บอก FE ว่านี่คือฟลว์ verify
        'data'    => [
            'user_id'         => (int)$rec['user_id'],
            'email'           => (string)$rec['email'],
            'profile_name'    => (string)($rec['profile_name'] ?? ''),
            'path_imgProfile' => (string)($rec['path_imgProfile'] ?? ''),
        ],
    ], 200);
    // (return ภายใน jsonOutput แล้ว)
}

// ── B) ลืมรหัสผ่าน (เคยยืนยันแล้ว) ──
// สร้าง reset_token แบบใช้ครั้งเดียว: ส่ง token จริงให้ FE, เก็บ hash ใน DB
$expires    = date('Y-m-d H:i:s', time() + $TOKEN_TTL);
$resetToken = bin2hex(random_bytes(32));   // 64 hex chars (256-bit)
$tokenHash  = hash('sha256', $resetToken);

dbExec("
    UPDATE user
       SET attempts               = 0,
           lock_until             = NULL,
           reset_token_hash       = ?,
           reset_token_expires_at = ?,
           otp                    = NULL,
           otp_expires_at         = NULL,
           otp_sent_at            = NULL
     WHERE email = ?
", [$tokenHash, $expires, $email]);

jsonOutput([
    'success'     => true,
    'message'     => 'ตรวจสอบรหัส OTP สำเร็จ',
    'flow'        => 'reset',       // บอก FE ว่านี่คือฟลว์ reset password
    'reset_token' => $resetToken,   // ใช้ครั้งเดียวใน new_password.php
    'expires_in'  => $TOKEN_TTL,    // วินาที
], 200);


