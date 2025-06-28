<?php
// reset_password.php — ส่ง OTP ไปอีเมลเพื่อรีเซ็ตรหัสผ่าน

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/* ───── config ───── */
const OTP_LEN      = 5;
const OTP_EXP_MIN  = 10;   // นาที
const COOLDOWN_SEC = 60;
const MAX_REQ      = 5;
const LOCK_SEC     = 300;

/* ───── Method Check ───── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* ───── Validate Email ───── */
$email = sanitize($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOutput(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง'], 400);
}

// 🐞 DEBUG log: ตรวจค่า email ที่รับมา
error_log("[reset_password] Request for email: {$email}");

/* ───── Email Exists? ───── */
$exists = dbVal('SELECT 1 FROM user WHERE email = ? LIMIT 1', [$email]);
if (!$exists) {
    jsonOutput(['success' => true, 'message' => 'หากลงทะเบียนไว้ ระบบจะส่งรหัสให้'], 200);
}

/* ───── OTP Status ───── */
$row = dbOne('SELECT otp_sent_at, request_attempts, request_lock_until FROM user_otp WHERE email = ? LIMIT 1', [$email]);

if ($row) {
    // ล็อกชั่วคราว
    if ($row['request_lock_until'] && time() < strtotime($row['request_lock_until'])) {
        $wait = strtotime($row['request_lock_until']) - time();
        jsonOutput(['success' => false, 'message' => "ขอรหัสถี่เกินไป กรุณารออีก {$wait} วินาที"], 429);
    }

    // เกิน MAX_REQ
    if ($row['request_attempts'] >= MAX_REQ) {
        $until = date('Y-m-d H:i:s', time() + LOCK_SEC);
        dbExec('UPDATE user_otp SET request_attempts = 0, request_lock_until = ? WHERE email = ?', [$until, $email]);
        jsonOutput(['success' => false, 'message' => "ขอรหัสเกินกำหนด – ล็อก " . LOCK_SEC . " วินาที"], 429);
    }

    // cool-down
    if ($row['otp_sent_at'] && (time() - strtotime($row['otp_sent_at'])) < COOLDOWN_SEC) {
        $wait = COOLDOWN_SEC - (time() - strtotime($row['otp_sent_at']));
        jsonOutput(['success' => false, 'message' => "กรุณารออีก {$wait} วินาที"], 429);
    }
}

/* ───── Generate OTP ───── */
$otp     = str_pad((string)random_int(0, intval(str_repeat('9', OTP_LEN))), OTP_LEN, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime('+' . OTP_EXP_MIN . ' minutes'));

// บันทึก OTP ลงฐานข้อมูล
if ($row) {
    dbExec(
        'UPDATE user_otp SET otp = ?, otp_expires_at = ?, otp_sent_at = ?, request_attempts = request_attempts + 1, request_lock_until = NULL WHERE email = ?',
        [$otp, $expires, $sentAt, $email]
    );
} else {
    dbExec(
        'INSERT INTO user_otp (email, otp, otp_expires_at, otp_sent_at, request_attempts) VALUES (?, ?, ?, ?, 1)',
        [$email, $otp, $expires, $sentAt]
    );
}

/* ───── Send Email ───── */
try {
    error_log("[reset_password] Preparing to send OTP {$otp} to {$email}");

    $mail = new PHPMailer(true);
    $mail->CharSet     = 'UTF-8';
    $mail->isSMTP();
    $mail->Host        = 'smtp.gmail.com';
    $mail->SMTPAuth    = true;
    $mail->Username    = 'okeza44@gmail.com';       // 👉 เปลี่ยนเป็นของคุณ
    $mail->Password    = 'ufhl etdx gfjh wrsl';      // 👉 ใช้ App Password จาก Gmail
    $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port        = 587;

    // เปิด debug ลง log
    $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        error_log("[SMTP DEBUG] {$str}");
    };

    // ถ้ามีปัญหาการเชื่อมต่อ SSL
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom('okeza44@gmail.com', 'Cooking Guide');
    $mail->addAddress($email); // ✅ ปลายทางคือ email ผู้ใช้งานจริง
    $mail->isHTML(false);
    $mail->Subject = 'OTP สำหรับรีเซ็ตรหัสผ่าน';
    $mail->Body    = "รหัส OTP ของคุณ: {$otp}\nใช้ได้ภายใน " . OTP_EXP_MIN . " นาที";

    $sent = $mail->send();
    error_log("[reset_password] mail->send() returned: " . ($sent ? 'true' : 'false'));

    jsonOutput(['success' => true, 'message' => 'ส่งรหัส OTP ไปยังอีเมลแล้ว']);
} catch (Throwable $e) {
    error_log('[reset_password] Exception: ' . $e->getMessage());
    if (isset($mail) && $mail instanceof PHPMailer) {
        error_log('[reset_password] Mailer ErrorInfo: ' . $mail->ErrorInfo);
    }
    jsonOutput(['success' => false, 'message' => 'ไม่สามารถส่งอีเมลได้'], 500);
}
