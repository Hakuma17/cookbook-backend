<?php
// reset_password.php — ส่ง OTP ไปอีเมลเพื่อรีเซ็ตรหัสผ่าน (ไทยล้วน • Mobile-friendly)
// เป้าหมาย:
// - โหลด .env ผ่าน bootstrap.php (เพื่อให้ getenv() ได้ค่า SMTP_* ถูกต้อง)
// - ใช้ inc/mailer.php → makeMailerFromEnv() (ลบรอยฮาร์ดโค้ด, จัดการช่องว่างในรหัส Gmail ให้อัตโนมัติ)
// - คงนโยบาย privacy: ถ้าอีเมลไม่พบ ให้ตอบกลาง ๆ เหมือนเดิม

require_once __DIR__ . '/bootstrap.php';        // ★ โหลด autoload + .env (มี fallback)
require_once __DIR__ . '/inc/functions.php';    // sanitize(), jsonOutput(), db helpers
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/mailer.php';       // ★ ใช้ makeMailerFromEnv()

use PHPMailer\PHPMailer\PHPMailer;

/* ───── Config (อ่านได้จาก .env ด้วย ถ้าอยาก override) ───── */
$OTP_LEN      = (int)(getenv('OTP_LEN')      ?: 6);
$OTP_EXP_MIN  = (int)(getenv('OTP_EXP_MIN')  ?: 10);
$COOLDOWN_SEC = (int)(getenv('COOLDOWN_SEC') ?: 60);
$MAX_REQ      = (int)(getenv('MAX_REQ')      ?: 5);
$LOCK_SEC     = (int)(getenv('LOCK_SEC')     ?: 300);

$brandName    = getenv('APP_BRAND_NAME') ?: 'Cooking Guide';
$supportEmail = getenv('SUPPORT_EMAIL')  ?: 'support@example.com';
$appUrl       = rtrim(
    getenv('APP_URL') ?: (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    '/'
);

/* ───── Method Check ───── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'วิธีเรียกไม่ถูกต้อง'], 405);
}

/* ───── Validate Email ───── */
$email = strtolower(trim(sanitize($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'], 400);
}

/* ───── Email Exists? (ตอบแบบไม่เปิดเผยข้อมูล) ───── */
$user = dbOne('SELECT user_id, otp_sent_at, request_attempts, request_lock_until FROM user WHERE email = ? LIMIT 1', [$email]);
if (!$user) {
    // ตอบกลาง ๆ เสมอ เพื่อลดการเดาอีเมล
    jsonOutput([
        'success'      => true,
        'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
    ], 200);
}

/* ───── Rate Limiting ───── */
if (!empty($user['request_lock_until']) && time() < strtotime($user['request_lock_until'])) {
    $wait = strtotime($user['request_lock_until']) - time();
    jsonOutput(['success' => false, 'message' => "ขอรหัสถี่เกินไป กรุณารออีก {$wait} วินาที"], 429);
}

if ((int)$user['request_attempts'] >= $MAX_REQ) {
    $until = date('Y-m-d H:i:s', time() + $LOCK_SEC);
    dbExec('UPDATE user SET request_attempts = 0, request_lock_until = ? WHERE email = ?', [$until, $email]);
    jsonOutput(['success' => false, 'message' => "ขอรหัสเกินกำหนด – ล็อก {$LOCK_SEC} วินาที"], 429);
}

if (!empty($user['otp_sent_at']) && (time() - strtotime($user['otp_sent_at'])) < $COOLDOWN_SEC) {
    $wait = $COOLDOWN_SEC - (time() - strtotime($user['otp_sent_at']));
    jsonOutput(['success' => false, 'message' => "กรุณารออีก {$wait} วินาที"], 429);
}

/* ───── Generate & Save OTP ───── */
$otp     = str_pad((string)random_int(0, (int)str_repeat('9', $OTP_LEN)), $OTP_LEN, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime('+'.$OTP_EXP_MIN.' minutes'));

dbExec("
    UPDATE user 
       SET otp = ?, otp_expires_at = ?, otp_sent_at = ?, 
           request_attempts = request_attempts + 1, 
           request_lock_until = NULL 
     WHERE email = ?
", [$otp, $expires, $sentAt, $email]);

/* ───── Prepare Email (Pastel Brown Minimal Template) ───── */
$tpl      = buildOtpEmail($brandName, $otp, $OTP_EXP_MIN, 'reset', $supportEmail, $appUrl);
$subject  = $tpl['subject'];
$html     = $tpl['html'];
$altText  = $tpl['alt'];

/* ───── Send Email ───── */
try {
    // ★ ใช้ mailer กลาง (อ่าน SMTP_* จาก .env แล้วลบช่องว่างในรหัส Gmail ให้อัตโนมัติ)
    $mail = makeMailerFromEnv();

    // setFrom ถูกตั้งเป็น SMTP_USER ใน makeMailerFromEnv() แล้ว
    $mail->addAddress($email);
    if ($supportEmail) {
        // ถ้ามีอีเมลซัพพอร์ต: ตั้ง reply-to ไปหาทีมซัพพอร์ต
        $mail->addReplyTo($supportEmail, $brandName);
    }

  $mail->Subject = $subject; // ไม่มี OTP ในหัวข้อ
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $altText;

    // ป้องกัน auto-replies/lists บางชนิด
    $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
    $mail->addCustomHeader('Auto-Submitted', 'auto-generated');

    $mail->send();

    // ตอบแบบไม่เปิดเผยว่าอีเมลนี้มีบัญชีหรือไม่
    jsonOutput([
        'success'      => true,
        'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
    ], 200);

} catch (\Throwable $e) {
    error_log('[reset_password] send failed: ' . $e->getMessage());
    // ยังตอบกลาง ๆ เพื่อความปลอดภัย
    jsonOutput([
        'success'      => true,
        'message'      => 'หากอีเมลนี้เคยลงทะเบียน ระบบจะส่งรหัสให้',
        'ttl_sec'      => $OTP_EXP_MIN * 60,
        'cooldown_sec' => $COOLDOWN_SEC,
    ], 200);
}
