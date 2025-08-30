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

/* ───── Prepare Email (ไทยล้วน • Mobile-friendly) ───── */
$year    = date('Y');
$subject = "รหัสรีเซ็ตรหัสผ่านสำหรับ {$brandName} คือ: {$otp}";

$brandEsc   = htmlspecialchars($brandName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$otpEsc     = htmlspecialchars($otp,       ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$appUrlEsc  = htmlspecialchars($appUrl,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$supportEsc = htmlspecialchars($supportEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<!doctype html>
<html lang="th" style="background:#f4f5f7">
  <head>
    <meta charset="utf-8">
    <title>{$subject}</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <style>
      @media (max-width:480px){
        .wrap{padding:16px !important}
        .card{border-radius:12px !important}
        .otp {font-size:26px !important; letter-spacing:6px !important}
        .btn {display:block !important; width:100% !important; text-align:center !important}
      }
      a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}
    </style>
  </head>
  <body style="margin:0;padding:0;background:#f4f5f7">
    <!-- preheader -->
    <div style="display:none;max-height:0;overflow:hidden;opacity:0">
      ใช้รหัสนี้เพื่อรีเซ็ตรหัสผ่าน ภายใน {$OTP_EXP_MIN} นาที: {$otpEsc}
    </div>

    <table role="presentation" style="width:100%;border-collapse:collapse;background:#f4f5f7">
      <tr>
        <td align="center" class="wrap" style="padding:24px">
          <table role="presentation" class="card" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e6e8eb;border-radius:16px;overflow:hidden">
            <!-- Header -->
            <tr>
              <td style="background:#0f172a;color:#ffffff;padding:16px 20px;font:700 18px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">
                {$brandEsc}
              </td>
            </tr>

            <!-- Content -->
            <tr>
              <td style="padding:28px 22px 8px 22px;font:400 15px/1.7 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a">
                <h1 style="margin:0 0 8px 0;font:700 20px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">รีเซ็ตรหัสผ่านของคุณ</h1>
                <p style="margin:0 0 12px 0;color:#334155">
                  คุณได้รับอีเมลฉบับนี้เนื่องจากมีการร้องขอรีเซ็ตรหัสผ่านใน <strong>{$brandEsc}</strong> โดยใช้อีเมลนี้
                </p>
                <p style="margin:0 0 12px 0;color:#334155">
                  โปรดนำรหัสยืนยัน (OTP) ด้านล่างไปกรอกในแอป/เว็บไซต์เพื่อดำเนินการรีเซ็ตรหัสผ่าน
                </p>
                <div style="text-align:center;margin:16px 0 20px 0">
                  <div class="otp"
                       style="display:inline-block;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:14px 18px;
                              font:700 30px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:8px;color:#0f172a">
                    {$otpEsc}
                  </div>
                </div>
                <p style="margin:0 0 16px 0;color:#334155">
                  รหัสนี้มีอายุการใช้งาน <strong>{$OTP_EXP_MIN} นาที</strong> นับจากเวลาที่ส่ง
                </p>
                <p style="margin:0 0 12px 0;color:#475569;font-size:14px">
                  หากคุณไม่ได้ร้องขอ กรุณา <strong>เพิกเฉย</strong> หรือลบอีเมลฉบับนี้ได้ทันที
                  และโปรดอย่าเปิดเผยรหัสนี้กับผู้อื่น
                </p>
                <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0">
                <p style="margin:0 0 4px 0;color:#64748b;font-size:14px">
                  ต้องการความช่วยเหลือ? ติดต่อทีมงานได้ที่
                  <a href="mailto:{$supportEsc}" style="color:#0ea5e9;text-decoration:underline">{$supportEsc}</a>
                </p>
                <p style="margin:0;color:#94a3b8;font-size:12px">
                  ข้อความนี้ถูกส่งโดยอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้
                </p>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="background:#f8fafc;color:#64748b;padding:14px 20px;font:400 12px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">
                © {$year} {$brandEsc}. สงวนลิขสิทธิ์ • <a href="{$appUrlEsc}" style="color:#64748b;text-decoration:underline">{$appUrlEsc}</a>
              </td>
            </tr>
          </table>

          <div style="font:400 11px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#94a3b8;margin-top:10px">
            หากรูปแบบไม่แสดงผล กรุณาคัดลอกรหัส <strong>{$otpEsc}</strong> ไปกรอกในแอปโดยตรง
          </div>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

$altText = <<<TXT
{$brandName} — รีเซ็ตรหัสผ่าน

คุณได้รับอีเมลฉบับนี้เนื่องจากมีการร้องขอรีเซ็ตรหัสผ่านใน {$brandName} โดยใช้อีเมลนี้
โปรดนำรหัสยืนยัน (OTP) ต่อไปนี้ไปกรอกในแอป/เว็บไซต์เพื่อดำเนินการรีเซ็ตรหัสผ่าน:

รหัส OTP: {$otp}
อายุรหัส: {$OTP_EXP_MIN} นาที นับจากเวลาที่ส่ง

หากคุณไม่ได้ร้องขอ กรุณาเพิกเฉยหรือลบอีเมลฉบับนี้ และอย่าเปิดเผยรหัสนี้กับผู้อื่น

ต้องการความช่วยเหลือ ติดต่อ: {$supportEmail}
เว็บไซต์: {$appUrl}
© {$year} {$brandName}
TXT;

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

    $mail->Subject = $subject;
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
