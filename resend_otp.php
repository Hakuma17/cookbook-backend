<?php
// resend_otp.php — ขอ OTP ซ้ำ (รองรับ purpose=verify|reset)
// แก้ไขหลัก: require bootstrap.php และใช้ makeMailerFromEnv()

require_once __DIR__ . '/bootstrap.php';   // ★ โหลด .env
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/mailer.php';  // ★ ใช้ mailer กลาง

use PHPMailer\PHPMailer\PHPMailer;

/* ───── Config ───── */
$OTP_LEN      = (int)(getenv('OTP_LEN')      ?: 6);
$OTP_EXP_MIN  = (int)(getenv('OTP_EXP_MIN')  ?: 10);
$COOLDOWN_SEC = (int)(getenv('COOLDOWN_SEC') ?: 60);
$MAX_REQ      = (int)(getenv('MAX_REQ')      ?: 5);
$LOCK_SEC     = (int)(getenv('LOCK_SEC')     ?: 300);

$brandName    = getenv('APP_BRAND_NAME') ?: 'Cooking Guide';
$supportEmail = getenv('SUPPORT_EMAIL')  ?: 'support@example.com';
$appUrl       = rtrim(getenv('APP_URL') ?: (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'วิธีเรียกไม่ถูกต้อง'], 405);
}

/* ───── รับค่า ───── */
$email   = strtolower(trim(sanitize($_POST['email']   ?? '')));
$purpose = strtolower(trim(sanitize($_POST['purpose'] ?? 'verify'))); // verify | reset (default=verify)

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'], 400);
}
if (!in_array($purpose, ['verify', 'reset'], true)) {
    jsonOutput(['success' => false, 'message' => 'purpose ไม่ถูกต้อง (verify|reset)'], 400);
}

/* ───── หา user ───── */
$u = dbOne("
    SELECT user_id, email, is_verified, otp_sent_at, request_attempts, request_lock_until
      FROM user
     WHERE email = ?
     LIMIT 1
", [$email]);

/* ───── Privacy ───── */
if (!$u) {
    jsonOutput([
        'success'      => true,
        'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
        'purpose'      => $purpose,
    ], 200);
}
if ($purpose === 'verify' && (int)$u['is_verified'] === 1) {
    jsonOutput(['success' => false, 'message' => 'บัญชีนี้ยืนยันอีเมลแล้ว'], 400);
}
if ($purpose === 'reset' && (int)$u['is_verified'] !== 1) {
    jsonOutput([
        'success'      => true,
        'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
        'purpose'      => $purpose,
    ], 200);
}

/* ───── Rate limiting ───── */
if (!empty($u['request_lock_until']) && time() < strtotime($u['request_lock_until'])) {
    $wait = max(0, strtotime($u['request_lock_until']) - time());
    jsonOutput([
        'success'     => false,
        'message'     => "กรุณารออีก {$wait} วินาที",
        'errorCode'   => 'LOCKED',
        'secondsLeft' => $wait,
        'purpose'     => $purpose,
    ], 429);
}

$attempts = (int)($u['request_attempts'] ?? 0);
if ($attempts >= $MAX_REQ) {
    $until = date('Y-m-d H:i:s', time() + $LOCK_SEC);
    dbExec("UPDATE user SET request_attempts=0, request_lock_until=? WHERE email=?", [$until, $email]);
    jsonOutput([
        'success'     => false,
        'message'     => "ขอรหัสเกินจำนวนที่กำหนด — ล็อก {$LOCK_SEC} วินาที",
        'errorCode'   => 'LOCKED',
        'secondsLeft' => $LOCK_SEC,
        'purpose'     => $purpose,
    ], 429);
}

$lastSentTs = $u['otp_sent_at'] ? strtotime($u['otp_sent_at']) : 0;
$elapsed    = $lastSentTs ? (time() - $lastSentTs) : $COOLDOWN_SEC + 1;
if ($elapsed < $COOLDOWN_SEC) {
    $wait = $COOLDOWN_SEC - $elapsed;
    jsonOutput([
        'success'     => false,
        'message'     => "กรุณารอสักครู่ (อีก {$wait} วินาที)",
        'errorCode'   => 'RATE_LIMIT',
        'secondsLeft' => $wait,
        'purpose'     => $purpose,
    ], 429);
}

/* ───── ออก OTP แล้วบันทึก ───── */
$otp     = str_pad((string)random_int(0, (int)str_repeat('9', $OTP_LEN)), $OTP_LEN, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime('+'.$OTP_EXP_MIN.' minutes'));

dbExec("
    UPDATE user
       SET otp = ?, otp_expires_at = ?, otp_sent_at = ?,
           request_attempts = COALESCE(request_attempts,0) + 1,
           request_lock_until = NULL
     WHERE email = ?
", [$otp, $expires, $sentAt, $email]);

/* ───── สร้างอีเมล (HTML + plain) ───── */
// Use shared pastel-brown template (no OTP in subject)
$tpl      = buildOtpEmail($brandName, $otp, $OTP_EXP_MIN, $purpose, $supportEmail, $appUrl);
$subject  = $tpl['subject'];
$html     = $tpl['html'];
$altText  = $tpl['alt'];

/* ───── ส่งอีเมล ───── */
try {
    $m = makeMailerFromEnv();          // ★ ใช้ mailer กลาง
    $m->addAddress($email);
    if ($supportEmail) $m->addReplyTo($supportEmail, $brandName);

  $m->Subject = $subject; // ไม่มี OTP ในหัวข้อ
    $m->isHTML(true);
    $m->Body    = $html;
    $m->AltBody = $altText;

    $m->addCustomHeader('X-Auto-Response-Suppress','All');
    $m->addCustomHeader('Auto-Submitted','auto-generated');

    $m->send();

    // ตอบกลับ (verify แจ้งส่งสำเร็จ, reset ตอบกลาง ๆ)
    if ($purpose === 'verify') {
        $resp = [
            'success'      => true,
            'message'      => 'ส่งรหัสยืนยันใหม่แล้ว',
            'ttl_sec'      => $OTP_EXP_MIN * 60,
            'cooldown_sec' => $COOLDOWN_SEC,
            'purpose'      => $purpose,
        ];
        if (in_array(strtolower((string)getenv('APP_ENV')), ['local','dev','development'], true)) {
            $resp['otp_preview'] = $otp;
        }
        jsonOutput($resp, 200);
    } else {
        jsonOutput([
            'success'      => true,
            'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
            'ttl_sec'      => $OTP_EXP_MIN * 60,
            'cooldown_sec' => $COOLDOWN_SEC,
            'purpose'      => $purpose,
        ], 200);
    }

} catch (\Throwable $e) {
    error_log('[resend_otp] send failed: '.$e->getMessage());
    $msg = ($purpose === 'verify')
        ? 'ไม่สามารถส่งอีเมลได้ กรุณาลองใหม่'
        : 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้';

    jsonOutput([
        'success'      => true,  // reset: คงตอบ 200 เพื่อ privacy
        'message'      => $msg,
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
        'purpose'      => $purpose,
    ], 200);
}
