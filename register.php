<?php
// register.php — สมัครสมาชิก + ส่ง OTP ยืนยันอีเมล (มี email_sent)

require_once __DIR__ . '/inc/functions.php';   // helper: sanitize, jsonOutput
require_once __DIR__ . '/inc/db.php';          // helper: dbVal, dbExec
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/* ───── Method check ───── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'errors' => ['Method not allowed']], 405);
}

/* ───── รับค่า ───── */
$email    = trim(sanitize($_POST['email']            ?? ''));
$pass     =        $_POST['password']         ?? '';
$confirm  =        $_POST['confirm_password'] ?? '';
$userName = trim(sanitize($_POST['username']         ?? ''));

/* ───── ตรวจอินพุต ───── */
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
if ($errs) {
    jsonOutput(['success' => false, 'errors' => $errs], 400);
}

/* ───── เช็คอีเมลซ้ำ ───── */
$dup = dbVal("SELECT 1 FROM user WHERE email = ? LIMIT 1", [$email]);
if ($dup) {
    jsonOutput(['success' => false, 'errors' => ['อีเมลนี้มีอยู่แล้ว']], 400);
}

/* ───── เตรียม OTP/เวลา ───── */
$otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT); // สุ่ม 6 หลัก
$sentAt    = date('Y-m-d H:i:s');                                   // เวลาออก OTP
$expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));         // อายุ 10 นาที

/* ───── เขียนผู้ใช้ ───── */
$hash = password_hash($pass, PASSWORD_ARGON2ID); // แฮชรหัสผ่าน
$ok   = dbExec("
    INSERT INTO user 
        (email, password, profile_name, created_at, is_verified, otp, otp_sent_at, otp_expires_at, request_attempts)
    VALUES 
        (?, ?, ?, NOW(), 0, ?, ?, ?, 1)
", [$email, $hash, $userName, $otp, $sentAt, $expiresAt]);

/* ───── ส่งอีเมล OTP ───── */
$emailSent = false; // ธงว่ามีการส่งจริงไหม
if ($ok) {
    try {
        // อ่าน SMTP จาก ENV (อย่า hardcode)
        $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $smtpUser = getenv('SMTP_USER') ?: 'okeza44@gmail.com';
        $smtpPass = getenv('SMTP_PASS') ?: 'nlpi ancy nkiq wjwn';             // ต้องตั้งผ่าน ENV
        $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Cooking Guide';

        $mail = new PHPMailer(true);
        $mail->CharSet     = 'UTF-8';                     // ภาษาไทย
        $mail->isSMTP();                                  // ใช้ SMTP
        $mail->Host        = $smtpHost;
        $mail->SMTPAuth    = true;
        $mail->Username    = $smtpUser;
        $mail->Password    = $smtpPass;
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = $smtpPort;

        // เปิด debug เฉพาะ DEV
        if (getenv('SMTP_DEBUG') === '1') {
            $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("[SMTP DEBUG] {$str}");
            };
        }

        // (ออปชัน DEV) ยอม self-signed ถ้าตั้งค่าไว้
        if (getenv('SMTP_ALLOW_SELF_SIGNED') === '1') {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        // ที่อยู่ผู้ส่ง/ผู้รับ
        $mail->setFrom($smtpUser, $fromName);
        $mail->addAddress($email);
        $mail->addReplyTo($smtpUser, $fromName);

        // เนื้อหา (plain text)
        $mail->isHTML(false);
        $mail->Subject = 'OTP สำหรับยืนยันอีเมล';
        $mail->Body    = "รหัส OTP ของคุณ: {$otp}\nใช้ได้ภายใน 10 นาที";

        // ส่ง
        $mail->send();
        $emailSent = true;
        error_log("[register] OTP email SENT to {$email}");
    } catch (Throwable $e) {
        // ล้มเหลว → แค่ log ไม่ทำให้การสมัคร fail
        $emailSent = false;
        error_log("[register] OTP email FAILED for {$email}: " . $e->getMessage());
        if (isset($mail)) {
            error_log("[register] Mailer ErrorInfo: " . $mail->ErrorInfo);
        }
    }
}

/* ───── ตอบกลับ FE ───── */
// success:true เสมอเมื่อ insert สำเร็จ แต่แนบ email_sent ให้ FE ตัดสินใจไปหน้า OTP
if ($ok) {
    $resp = [
        'success'        => true,
        'email_sent'     => $emailSent,                    // ← สำคัญ
        'message'        => $emailSent
            ? 'สมัครสำเร็จ โปรดตรวจอีเมลเพื่อยืนยันด้วย OTP'
            : 'สมัครสำเร็จ แต่ยังส่งอีเมลไม่สำเร็จ โปรดลอง “ส่งรหัสอีกครั้ง” หรือเช็คสแปม',
        'otp_expires_at' => $expiresAt,                    // ใช้โชว์เวลาได้
        'ttl_sec'        => 600,                           // อายุ 600 วินาที
    ];

    // โหมด DEV: โชว์ OTP ใน response เพื่อเทสต์ง่าย ๆ
    if (in_array(getenv('APP_ENV'), ['local','dev','development'], true)) {
        $resp['otp_preview'] = $otp;
    }

    jsonOutput($resp, 200);
}

/* ───── กรณี insert พัง ───── */
jsonOutput(['success' => false, 'errors' => ['สมัครไม่สำเร็จ ลองใหม่']], 500);
