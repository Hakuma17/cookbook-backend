<?php
// resend_otp.php — ขอ OTP ซ้ำสำหรับยืนยันอีเมล
//
// ★ เพิ่ม field ให้ FE ใช้งานคูลดาวน์ได้เนียนขึ้น:
//   - เมื่อชนนับถอยหลัง:   errorCode='RATE_LIMIT', secondsLeft=<int>, HTTP 429
//   - เมื่อถูกล็อกชั่วคราว: errorCode='LOCKED',     secondsLeft=<int>, HTTP 429
//
// หมายเหตุ: ไม่เปิดเผยการมีอยู่ของบัญชีในบางกรณี (privacy)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

const OTP_LEN      = 6;    // ← ให้ตรงกับ register.php
const OTP_EXP_MIN  = 10;   // อายุ OTP นาที
const COOLDOWN_SEC = 60;   // ขอถี่สุดทุก 60 วิ
const MAX_REQ      = 5;    // ขอซ้ำได้สูงสุดก่อนโดนล็อก
const LOCK_SEC     = 300;  // ล็อก 5 นาที เมื่อเกิน MAX_REQ

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* ───── รับและตรวจอีเมล ───── */
$email = trim(sanitize($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput(['success' => false, 'message' => 'อีเมลไม่ถูกต้อง'], 400);
}

error_log("[resend_otp] Request for {$email}");

/* ───── ค้นหาผู้ใช้ ───── */
$row = dbOne("
    SELECT is_verified, otp_sent_at, request_attempts, request_lock_until
      FROM user
     WHERE email = ?
     LIMIT 1
", [$email]);

if (!$row) {
    // ไม่เปิดเผยว่ามีบัญชี/ไม่มีบัญชี
    jsonOutput(['success' => true, 'message' => 'หากมีบัญชี ระบบจะส่งรหัสให้']);
}

if (!empty($row['is_verified'])) {
    jsonOutput(['success' => false, 'message' => 'บัญชีนี้ยืนยันอีเมลแล้ว'], 400);
}

/* ───── ตรวจล็อก / เรตลิมิต ───── */

// ล็อกชั่วคราวเพราะขอซ้ำเกินกำหนด
if (!empty($row['request_lock_until']) && time() < strtotime($row['request_lock_until'])) {
    $wait = max(0, strtotime($row['request_lock_until']) - time());
    jsonOutput([
        'success'     => false,
        'message'     => "กรุณารออีก {$wait} วินาที",
        'errorCode'   => 'LOCKED',       // ★ เพิ่มรหัสล็อก
        'secondsLeft' => $wait
    ], 429);
}

// ถึงเพดานจำนวนครั้ง → ตั้งล็อก 5 นาที แล้วแจ้งกลับ
$attempts = (int)($row['request_attempts'] ?? 0);
if ($attempts >= MAX_REQ) {
    $lockUntil = date('Y-m-d H:i:s', time() + LOCK_SEC);
    dbExec("UPDATE user SET request_attempts=0, request_lock_until=? WHERE email=?", [$lockUntil, $email]);

    jsonOutput([
        'success'     => false,
        'message'     => "ขอรหัสเกินจำนวนที่กำหนด — ล็อก 5 นาที",
        'errorCode'   => 'LOCKED',       // ★
        'secondsLeft' => LOCK_SEC
    ], 429);
}

// ยังไม่ครบคูลดาวน์ 60 วินาที
$lastSentTs = $row['otp_sent_at'] ? strtotime($row['otp_sent_at']) : 0;
$elapsed    = $lastSentTs ? (time() - $lastSentTs) : COOLDOWN_SEC + 1;
if ($elapsed < COOLDOWN_SEC) {
    $wait = COOLDOWN_SEC - $elapsed;
    jsonOutput([
        'success'     => false,
        'message'     => "กรุณารอสักครู่ (อีก {$wait} วินาที)",
        'errorCode'   => 'RATE_LIMIT',   // ★ เพิ่มรหัสเรตลิมิต
        'secondsLeft' => $wait
    ], 429);
}

/* ───── สร้าง/อัปเดต OTP ───── */
$otp     = str_pad(random_int(0, 999999), OTP_LEN, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime("+".OTP_EXP_MIN." minutes"));

dbExec("
    UPDATE user 
       SET otp                 = ?,
           otp_expires_at      = ?,
           otp_sent_at         = ?,
           request_attempts    = COALESCE(request_attempts,0) + 1,
           request_lock_until  = NULL
     WHERE email = ?
", [$otp, $expires, $sentAt, $email]);

/* ───── ส่งอีเมล OTP ───── */
try {
    // ★ ใช้ ENV เหมือน register.php
    $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtpUser = getenv('SMTP_USER') ?: 'you@example.com';         // เปลี่ยนให้เหมาะกับโปรดักชัน
    $smtpPass = getenv('SMTP_PASS') ?: 'app-password-here';       // ใช้ App Password
    $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Cooking Guide';

    $mail = new PHPMailer(true);
    $mail->CharSet     = 'UTF-8';
    $mail->isSMTP();
    $mail->Host        = $smtpHost;
    $mail->SMTPAuth    = true;
    $mail->Username    = $smtpUser;
    $mail->Password    = $smtpPass;
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = $smtpPort;

    if (getenv('SMTP_DEBUG') === '1') {
        $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) { error_log("[SMTP DEBUG] {$str}"); };
    }
    if (getenv('SMTP_ALLOW_SELF_SIGNED') === '1') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $mail->setFrom($smtpUser, $fromName);
    $mail->addAddress($email);
    $mail->isHTML(false);
    $mail->Subject = 'OTP ใหม่สำหรับยืนยันอีเมล';
    $mail->Body    = "รหัส OTP ของคุณ: {$otp}\nใช้ได้ภายใน " . OTP_EXP_MIN . " นาที";

    $mail->send();
    error_log("[resend_otp] OTP sent to {$email}");

    // โหมด DEV แถม OTP ให้เทสต์ (อย่าทำในโปรดักชัน)
    $resp = ['success' => true, 'message' => 'ส่ง OTP ใหม่แล้ว'];
    if (in_array(getenv('APP_ENV'), ['local','dev','development'], true)) {
        $resp['otp_preview'] = $otp; // ★ สำหรับ QA เท่านั้น
    }
    jsonOutput($resp);

} catch (Throwable $e) {
    error_log('[resend_otp] Send failed: ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'ไม่สามารถส่งอีเมลได้'], 500);
}
