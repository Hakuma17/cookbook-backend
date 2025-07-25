<?php
// register.php — สมัครสมาชิก (email + password + username)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/vendor/autoload.php'; // สำหรับ PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'errors' => ['Method not allowed']], 405);
}

// ─────── รับค่าจากผู้ใช้ ───────
$email    = trim(sanitize($_POST['email']            ?? ''));
$pass     =        $_POST['password']         ?? '';
$confirm  =        $_POST['confirm_password'] ?? '';
$userName = trim(sanitize($_POST['username']         ?? ''));

// ─────── ตรวจสอบความถูกต้อง ───────
$errs = [];
if ($email === '' || $pass === '' || $confirm === '' || $userName === '') {
    $errs[] = 'กรอกข้อมูลให้ครบ';
}
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errs[] = 'อีเมลไม่ถูกต้อง';
}
if ($pass !== $confirm) {
    $errs[] = 'รหัสผ่านไม่ตรงกัน';
}
if (strlen($pass) < 8) {
    $errs[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว';
}

// ─────── ตรวจ email ซ้ำ ───────
if (!$errs) {
    $dup = dbVal("SELECT 1 FROM user WHERE email = ? LIMIT 1", [$email]);
    if ($dup) {
        $errs[] = 'อีเมลนี้มีอยู่แล้ว';
    }
}

// ─────── มี error หรือไม่ ───────
if ($errs) {
    jsonOutput(['success' => false, 'errors' => $errs], 400);
}

// ─────── สร้าง OTP ───────
$otp         = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$sentAt      = date('Y-m-d H:i:s');
$expiresAt   = date('Y-m-d H:i:s', strtotime('+10 minutes'));
$isVerified  = 0;
$reqAttempts = 1; // ✨ เริ่มนับ request OTP สำหรับ rate-limit resend

// ─────── บันทึกลงฐานข้อมูล ───────
$hash = password_hash($pass, PASSWORD_ARGON2ID);
$ok   = dbExec("
    INSERT INTO user 
        (email, password, profile_name, created_at, is_verified, otp, otp_sent_at, otp_expires_at, request_attempts)
    VALUES 
        (?, ?, ?, NOW(), ?, ?, ?, ?, ?)
", [$email, $hash, $userName, $isVerified, $otp, $sentAt, $expiresAt, $reqAttempts]);

// ─────── ส่ง OTP ไปยังอีเมลด้วย PHPMailer ───────
if ($ok) {
    try {
        $mail = new PHPMailer(true);
        $mail->CharSet     = 'UTF-8';
        $mail->isSMTP();
        $mail->Host        = 'smtp.gmail.com';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'okeza44@gmail.com';       // ✅ แก้เป็นอีเมลของคุณ
        $mail->Password    = 'ufhl etdx gfjh wrsl';      // ✅ App Password
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
        $mail->Subject = 'OTP สำหรับยืนยันอีเมล';
        $mail->Body    = "รหัส OTP ของคุณ: {$otp}\nใช้ได้ภายใน 10 นาที";

        $mail->send();
        error_log("[register] OTP email sent to {$email}");
    } catch (Throwable $e) {
        error_log("[register] OTP email failed: " . $e->getMessage());
        if (isset($mail)) {
            error_log("[register] Mailer ErrorInfo: " . $mail->ErrorInfo);
        }
        // ไม่ถือว่า fatal error — ให้ผู้ใช้ยืนยัน OTP ผ่านทางอื่น
    }
}

// ─────── ส่งผลลัพธ์กลับ ───────
$ok
    ? jsonOutput(['success' => true, 'message' => 'สมัครสำเร็จ โปรดยืนยันอีเมลด้วย OTP'])
    : jsonOutput(['success' => false, 'errors' => ['สมัครไม่สำเร็จ ลองใหม่']], 500);
