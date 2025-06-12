<?php
// reset_password.php
// ส่ง OTP ทางอีเมล พร้อมจำกัดการขอเกินและล็อกสแปม
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors',1); ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';       // PHPMailer
require_once __DIR__ . '/inc/config.php';        // $pdo, BASE_URL
require_once __DIR__ . '/inc/functions.php';     // sanitize()

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// — constants ———————————————————————————————
define('OTP_LENGTH',        5);     // หลัก OTP
define('OTP_EXP_MINUTES',  10);     // อายุ OTP (นาที)
define('COOLDOWN_SECONDS', 60);     // รอขอรหัสใหม่ขั้นต่ำ (วินาที)
define('MAX_REQUESTS',      5);     // ขอ OTP เกินรอบนี้ให้ล็อก
define('REQUEST_LOCK_SEC', 300);    // ล็อกขอรหัสใหม่ (วินาที)

// 1) POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success'=>false,'message'=>'Method not allowed']));
}

// 2) sanitize & validate email
$email = sanitize($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(['success'=>false,'message'=>'รูปแบบอีเมลไม่ถูกต้อง']));
}

// 3) ensure user exists
$stmt = $pdo->prepare('SELECT user_id FROM user WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) {
    // ไม่เปิดเผยว่ามีหรือไม่
    echo json_encode(['success'=>true,'message'=>'หากอีเมลนี้ลงทะเบียนไว้ ระบบจะส่งรหัสให้']);
    exit;
}

// 4) fetch previous OTP state
$chk = $pdo->prepare(
  'SELECT otp_sent_at, request_attempts, request_lock_until
     FROM user_otp WHERE email=?'
);
$chk->execute([$email]);
$row = $chk->fetch(PDO::FETCH_ASSOC);

// 5) block if in request-lock
if ($row && !empty($row['request_lock_until'])
    && time() < strtotime($row['request_lock_until'])
) {
    $wait = strtotime($row['request_lock_until']) - time();
    exit(json_encode([
      'success'=>false,
      'message'=>"ขอรหัสถี่เกินไป กรุณารออีก {$wait} วินาที"
    ]));
}

// 6) if requests exceed MAX → set lock and reset counter
if ($row && $row['request_attempts'] >= MAX_REQUESTS) {
    $lock = date('Y-m-d H:i:s', time() + REQUEST_LOCK_SEC);
    $pdo->prepare(
      'UPDATE user_otp
          SET request_attempts=0, request_lock_until=?
        WHERE email=?'
    )->execute([$lock, $email]);
    exit(json_encode([
      'success'=>false,
      'message'=>"ขอรหัสเกินกำหนด ระบบล็อกชั่วคราว ".REQUEST_LOCK_SEC." วินาที"
    ]));
}

// 7) enforce cooldown between sends
if ($row && !empty($row['otp_sent_at'])) {
    $last = strtotime($row['otp_sent_at']);
    if (time() - $last < COOLDOWN_SECONDS) {
        $wait = COOLDOWN_SECONDS - (time() - $last);
        exit(json_encode([
          'success'=>false,
          'message'=>"กรุณารออีก {$wait} วินาที ก่อนขอรหัสใหม่"
        ]));
    }
}

// 8) generate OTP & times
$max     = intval(str_repeat('9',OTP_LENGTH));      // e.g. 99999
$otp     = str_pad((string)random_int(0,$max), OTP_LENGTH, '0', STR_PAD_LEFT);
$sentAt  = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', strtotime("+".OTP_EXP_MINUTES." minutes"));

// 9) insert/update user_otp & increment request_attempts
if ($row) {
    $upd = $pdo->prepare("
      UPDATE user_otp SET
        otp            = :otp,
        otp_expires_at = :exp,
        otp_sent_at    = :sent,
        request_attempts = request_attempts + 1,
        request_lock_until = NULL
      WHERE email=:email
    ");
    $upd->execute([
      ':otp'=>$otp, ':exp'=>$expires,
      ':sent'=>$sentAt, ':email'=>$email
    ]);
} else {
    $ins = $pdo->prepare("
      INSERT INTO user_otp
        (email, otp, otp_expires_at, otp_sent_at, request_attempts)
      VALUES
        (:email,:otp,:exp,:sent,1)
    ");
    $ins->execute([
      ':email'=>$email,
      ':otp'=>$otp,
      ':exp'=>$expires,
      ':sent'=>$sentAt
    ]);
}

// 10) send mail with PHPMailer
$mail = new PHPMailer(true);
try {
    $mail->CharSet      = 'UTF-8';
    $mail->Encoding     = PHPMailer::ENCODING_BASE64;
    $mail->isSMTP();
    $mail->Host         = 'smtp.gmail.com';   // <— เปลี่ยนถ้าจำเป็น
    $mail->SMTPAuth     = true;
    $mail->Username     = 'okeza44@gmail.com';// <— แก้เป็น SMTP user
    $mail->Password     = 'ufhl etdx gfjh wrsl';    // <— แก้เป็น App Password
    $mail->SMTPSecure   = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port         = 587;

    $mail->setFrom('okeza44@gmail.com','Cooking Guide');
    $mail->addAddress($email);
    $mail->isHTML(false);
    $mail->Subject      = 'รหัส OTP สำหรับรีเซ็ตรหัสผ่าน';
    $mail->Body         = 
"สวัสดีค่ะ/ครับ

รหัส OTP ของคุณคือ:
  {$otp}

ใช้ได้ภายใน ".OTP_EXP_MINUTES." นาที

หากคุณไม่ได้ขอรหัสนี้ โปรดเพิกเฉยข้อความนี้

ขอบคุณที่ใช้ Cooking Guide";

    $mail->send();
    echo json_encode(['success'=>true,'message'=>'ส่งรหัส OTP ไปยังอีเมลแล้ว']);
} catch (Exception $e) {
    error_log('Mail error: '.$mail->ErrorInfo);
    http_response_code(500);
    echo json_encode([
      'success'=>false,
      'message'=>'ไม่สามารถส่งอีเมลได้: '.$mail->ErrorInfo
    ]);
}

exit;
