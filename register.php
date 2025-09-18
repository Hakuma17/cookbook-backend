<?php
// register.php — สมัครสมาชิก + ส่ง OTP ยืนยันอีเมล (ไทยล้วน • Mobile-friendly)
require_once __DIR__ . '/inc/mailer.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/functions.php';   // ต้องมี jsonOutput(), sanitize()
require_once __DIR__ . '/inc/db.php';          // ต้องมี dbVal(), dbExec()


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'วิธีเรียกไม่ถูกต้อง', 'errors' => ['Method not allowed']], 405);
}

/* ───── ค่าจาก ENV (มีค่าเริ่มต้นกรณีไม่ตั้ง) ───── */
$brandName      = getenv('APP_BRAND_NAME') ?: 'Cooking Guide';
$supportEmail   = getenv('SUPPORT_EMAIL')  ?: 'support@example.com';
$appUrl         = rtrim(getenv('APP_URL') ?: (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

$otpLen         = max(4, (int)(getenv('OTP_LEN') ?: 6));      // อย่างน้อย 4 หลัก
$otpExpMin      = max(3, (int)(getenv('OTP_EXP_MIN') ?: 10));  // อย่างน้อย 3 นาที
$ttlSec         = $otpExpMin * 60;

$smtpHost       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtpUser       = getenv('SMTP_USER') ?: '';
$smtpPass       = getenv('SMTP_PASS') ?: '';
$smtpPort       = (int)(getenv('SMTP_PORT') ?: 587);
$smtpDebug      = (int)(getenv('SMTP_DEBUG') ?: 0);
$smtpAllowSelf  = (string)(getenv('SMTP_ALLOW_SELF_SIGNED') ?: '0') === '1';

$appEnv         = strtolower((string)getenv('APP_ENV'));

/* ───── รับค่า ───── */
$email    = strtolower(trim(sanitize($_POST['email']            ?? '')));
$pass     =                   $_POST['password']           ?? '';
$confirm  =                   $_POST['confirm_password'] ?? '';
$userName = trim(sanitize($_POST['username']           ?? ''));

/* ───── Helper: นับไบต์ (UTF-8) ───── */
$utf8ByteLen = static fn(string $s) => strlen($s);

/* ───── ตรวจอินพุต ───── */
$errs = [];
if ($email === '' || $pass === '' || $confirm === '' || $userName === '') {
    $errs[] = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errs[] = 'รูปแบบอีเมลไม่ถูกต้อง';
}
if (mb_strlen($email) > 100) {
    $errs[] = 'อีเมลยาวเกินไป (สูงสุด 100 ตัวอักษร)';
}
if ($pass !== $confirm) {
    $errs[] = 'รหัสผ่านไม่ตรงกัน';
}
if (strlen($pass) < 8) {
    $errs[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
}
if (!preg_match('/(?=.*[A-Za-z])(?=.*\d)/', $pass)) {
    $errs[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
}
/* [ปรับปรุง] ชื่อโปรไฟล์: ≥1 ตัว, ≤100 ไบต์ (ไม่จำกัดชนิดอักขระเพื่อความยืดหยุ่น) */
if (mb_strlen($userName) < 1) {
    $errs[] = 'กรุณาตั้งชื่อโปรไฟล์';
}
if ($utf8ByteLen($userName) > 100) {
    $errs[] = 'ชื่อโปรไฟล์ยาวเกินไป (เกิน 100 ไบต์)';
}
// [ปรับปรุง] ลบการจำกัดชนิดอักขระเพื่อให้ User-friendly มากขึ้น
// if (!preg_match('/^[A-Za-z0-9_.\-ก-ฮะ-์\h]+$/u', $userName)) {
//     $errs[] = 'ชื่อโปรไฟล์มีอักขระไม่ถูกต้อง';
// }
if ($errs) {
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $errs], 400);
}

/* ───── เช็คอีเมลซ้ำ ───── */
$dup = dbVal("SELECT 1 FROM user WHERE email = ? LIMIT 1", [$email]);
if ($dup) {
    jsonOutput(['success' => false, 'message' => 'อีเมลนี้ถูกใช้งานแล้ว', 'errors' => ['อีเมลนี้ถูกใช้งานแล้ว']], 409);
}

/* ───── เตรียม OTP/เวลา ───── */
$maxVal    = (int)str_repeat('9', $otpLen);
$otp       = str_pad((string)random_int(0, $maxVal), $otpLen, '0', STR_PAD_LEFT);
$sentAt    = date('Y-m-d H:i:s');
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$otpExpMin} minutes"));

/* ───── เขียนผู้ใช้ ───── */
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($pass, $algo);

$ok = dbExec("
    INSERT INTO user
        (email, password, profile_name, created_at, is_verified, otp, otp_sent_at, otp_expires_at, request_attempts)
    VALUES
        (?, ?, ?, NOW(), 0, ?, ?, ?, 1)
", [$email, $hash, $userName, $otp, $sentAt, $expiresAt]);

/* ───── เทมเพลตอีเมลใหม่ (Pastel Brown Minimal) ───── */
$tpl = buildOtpEmail($brandName, $otp, $otpExpMin, 'verify', $supportEmail, $appUrl);
$subject = $tpl['subject'];
$html    = $tpl['html'];
$altText = $tpl['alt'];

/* ───── ส่งอีเมล OTP ───── */
$emailSent = false;
if ($ok) {
    try {
        // ส่งครั้งที่ 1: ใช้ค่าใน .env ตรง ๆ
        $mail = makeMailerFromEnv();

        $reply = getenv('SMTP_USER') ?: $supportEmail; // reply-to
        $mail->addAddress($email);
        if ($reply) $mail->addReplyTo($reply, $brandName);

  $mail->Subject = $subject; // ไม่มี OTP ในหัวข้อ
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $altText;

        // ช่วยลดการตอบกลับอัตโนมัติ/ลูป
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');

        $mail->send();
        $emailSent = true;
        error_log("[register] OTP email SENT via port {$mail->Port} to {$email}");
    } catch (\Throwable $e) {
        error_log("[register] primary SMTP failed: " . $e->getMessage());
        if (isset($mail)) error_log("[register] Mailer ErrorInfo: " . $mail->ErrorInfo);

        // Fallback: สลับพอร์ต+การเข้ารหัส (587↔465) แล้วลองส่งอีกครั้ง
        try {
            $mail = makeMailerFromEnv();

            if ((int)$mail->Port !== 465) {
                // จาก 587 STARTTLS → ลอง 465 SMTPS
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = 465;
            } else {
                // ถ้า .env ตั้ง 465 อยู่แล้ว → ลองกลับ 587 STARTTLS
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
            }

            $reply = getenv('SMTP_USER') ?: $supportEmail;
            $mail->addAddress($email);
            if ($reply) $mail->addReplyTo($reply, $brandName);

            $mail->Subject = $subject; // ไม่มี OTP ในหัวข้อ
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $altText;
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');

            $mail->send();
            $emailSent = true;
            error_log("[register] fallback SMTP succeeded via port {$mail->Port} to {$email}");
        } catch (\Throwable $e2) {
            error_log("[register] fallback SMTP failed: " . $e2->getMessage());
            if (isset($mail)) error_log("[register] Fallback ErrorInfo: " . $mail->ErrorInfo);
        }
    }
}


/* ───── ตอบกลับ FE ───── */
if ($ok) {
    $resp = [
        'success'        => true,
        'email_sent'     => $emailSent,
        'message'        => $emailSent
            ? 'ลงทะเบียนสำเร็จ! โปรดใช้รหัส OTP จากอีเมลเพื่อยืนยันบัญชี'
            : 'ลงทะเบียนสำเร็จ แต่ส่งอีเมลยืนยันล้มเหลว โปรดลอง “ขอรหัสใหม่”',
        'otp_expires_at' => $expiresAt,
        'ttl_sec'        => $ttlSec,
    ];
    if (in_array($appEnv, ['local','dev','development'], true)) {
        $resp['otp_preview'] = $otp; // เพื่อทดสอบใน DEV เท่านั้น
    }
    jsonOutput($resp, 201);
} else {
    jsonOutput(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลงทะเบียน โปรดลองอีกครั้ง'], 500);
}