<?php
// resend_otp.php — ขอ OTP ซ้ำสำหรับยืนยันอีเมล

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

const OTP_LEN      = 6;
const OTP_EXP_MIN  = 10;    // นาที
const COOLDOWN_SEC = 60;    // ขอถี่สุดทุก 60 วิ
const MAX_REQ      = 5;     // ขอซ้ำได้สูงสุด
const LOCK_SEC     = 300;   // ถ้าเกิน → ล็อก 5 นาที

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ⛑ trim และ validate email
$email = trim(sanitize($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput(['success' => false, 'message' => 'อีเมลไม่ถูกต้อง'], 400);
}

error_log("[resend_otp] Request for {$email}");

/* ───── ค้นหาผู้ใช้ ───── */
$row = dbOne("SELECT is_verified, otp_sent_at, request_attempts, request_lock_until FROM user WHERE email=? LIMIT 1", [$email]);

if (!$row) {
    // ไม่เปิดเผยว่ามี user หรือไม่
    jsonOutput(['success' => true, 'message' => 'หากมีบัญชี ระบบจะส่งรหัสให้']);
}

if ($row['is_verified']) {
    jsonOutput(['success' => false, 'message' => 'บัญชีนี้ยืนยันอีเมลแล้ว'], 400);
}

/* ───── ตรวจ rate-limit ───── */
if ($row['request_lock_until'] && time() < strtotime($row['request_lock_until'])) {
    $wait = strtotime($row['request_lock_until']) - time();
    jsonOutput(['success' => false, 'message' => "กรุณารออีก {$wait} วินาที"], 429);
}

if ($row['request_attempts'] >= MAX_REQ) {
    $lockUntil = date('Y-m-d H:i:s', time() + LOCK_SEC);
    dbExec("UPDATE user SET request_attempts=0, request_lock_until=? WHERE email=?", [$lockUntil, $email]);
    jsonOutput(['success' => false, 'message' => "ขอรหัสเกินจำนวนที่กำหนด — ล็อก 5 นาที"], 429);
}

if ($row['otp_sent_at'] && (time() - strtotime($row['otp_sent_at'])) < COOLDOWN_SEC) {
    $wait = COOLDOWN_SEC - (time() - strtotime($row['otp_sent_at']));
    jsonOutput(['success' => false, 'message' => "กรุณารอสักครู่ (อีก {$wait} วินาที)"], 429);
}

/* ───── สร้าง OTP ใหม่ ───── */
$otp     = str_pad(random_int(0, 999999), OTP_LEN, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime("+".OTP_EXP_MIN." minutes"));

dbExec("
    UPDATE user 
    SET otp=?, otp_expires_at=?, otp_sent_at=?, 
        request_attempts=request_attempts+1, 
        request_lock_until=NULL 
    WHERE email=?", 
    [$otp, $expires, $sentAt, $email]);

/* ───── ส่งอีเมล OTP ───── */
try {
    $mail = new PHPMailer(true);
    $mail->CharSet     = 'UTF-8';
    $mail->isSMTP();
    $mail->Host        = 'smtp.gmail.com';
    $mail->SMTPAuth    = true;
    $mail->Username    = 'okeza44@gmail.com';       //  เปลี่ยนเป็นอีเมลของคุณ
    $mail->Password    = 'ufhl etdx gfjh wrsl';      //  ใช้ App Password
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = 587;

    $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        error_log("[SMTP DEBUG] {$str}");
    };
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom('okeza44@gmail.com', 'Cooking Guide');
    $mail->addAddress($email);
    $mail->isHTML(false);
    $mail->Subject = 'OTP ใหม่สำหรับยืนยันอีเมล';
    $mail->Body    = "รหัส OTP ของคุณ: {$otp}\nใช้ได้ภายใน " . OTP_EXP_MIN . " นาที";

    $mail->send();
    error_log("[resend_otp] OTP sent to {$email}");
    jsonOutput(['success' => true, 'message' => 'ส่ง OTP ใหม่แล้ว']);
} catch (Throwable $e) {
    error_log('[resend_otp] Send failed: ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'ไม่สามารถส่งอีเมลได้'], 500);
}
