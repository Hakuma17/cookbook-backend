
<?php
// inc/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

function makeMailerFromEnv(): PHPMailer {
    $brand = getenv('APP_BRAND_NAME') ?: 'Cooking Guide';
    $host  = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $user  = getenv('SMTP_USER') ?: '';
    $pass  = getenv('SMTP_PASS') ?: '';
    // กันเคสเผลอวางรหัสจาก Gmail ที่มีช่องว่าง
    $pass  = str_replace(' ', '', $pass);

    $port  = (int)(getenv('SMTP_PORT') ?: 587);
    $debug = (int)(getenv('SMTP_DEBUG') ?: 0);
    $allowSelf = (string)(getenv('SMTP_ALLOW_SELF_SIGNED') ?: '0') === '1';

    if ($user === '' || $pass === '') {
        throw new RuntimeException('SMTP not configured (user/pass empty).');
    }

    $mail = new PHPMailer(true);
    $mail->CharSet    = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;

    // เลือก encryption ตามพอร์ต
    if ($port === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port = $port;

    if ($debug === 1) {
        $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) { error_log("[SMTP DEBUG] {$str}"); };
    }
    if ($allowSelf) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    // แนะนำให้ setFrom เป็นบัญชีที่ login จริง เพื่อลดปัญหา DMARC
    $mail->setFrom($user, $brand);

    return $mail;
}

/**
 * Build a minimal pastel-brown themed OTP email (HTML + plain text) without leaking the code in the subject.
 * @param string $brandName  Brand / product name.
 * @param string $otp        OTP code (already generated, numeric or alphanumeric).
 * @param int    $expiryMin  Expiry in minutes (display only).
 * @param string $purpose    verify | reset (affects wording).
 * @param string $supportEmail Optional support contact.
 * @param string $appUrl       Optional site/app URL.
 * @return array{subject:string,html:string,alt:string,preheader:string}
 */
function buildOtpEmail(string $brandName, string $otp, int $expiryMin, string $purpose = 'verify', string $supportEmail = '', string $appUrl = ''): array {
        $purpose = strtolower($purpose);
        if (!in_array($purpose, ['verify','reset'], true)) $purpose = 'verify';

        $brandEsc   = htmlspecialchars($brandName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $otpEsc     = htmlspecialchars($otp,       ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $supportEsc = htmlspecialchars($supportEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $appUrlEsc  = htmlspecialchars($appUrl,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $year       = date('Y');

        if ($purpose === 'reset') {
                $subject   = "รีเซ็ตรหัสผ่านสำหรับ {$brandName}"; // ไม่ใส่ OTP
                $headline  = 'รีเซ็ตรหัสผ่านของคุณ';
                $reason    = 'คุณได้รับอีเมลฉบับนี้เนื่องจากมีการร้องขอรีเซ็ตรหัสผ่านใน <strong>'.$brandEsc.'</strong>';
                $cta       = 'ใช้รหัสยืนยัน (OTP) ด้านล่างนี้ภายในเวลาที่กำหนดเพื่อดำเนินการต่อ';
                $reasonAlt = 'คุณได้รับอีเมลฉบับนี้เนื่องจากมีการร้องขอรีเซ็ตรหัสผ่านใน '.$brandName;
        } else {
                $subject   = "ยืนยันตัวตนสำหรับ {$brandName}"; // ไม่ใส่ OTP
                $headline  = 'ยืนยันอีเมลของคุณ';
                $reason    = 'คุณได้รับอีเมลฉบับนี้เนื่องจากมีการสมัครสมาชิก <strong>'.$brandEsc.'</strong>';
                $cta       = 'นำรหัสยืนยัน (OTP) ด้านล่างไปกรอกในแอป/เว็บไซต์เพื่อยืนยันบัญชี';
                $reasonAlt = 'คุณได้รับอีเมลฉบับนี้เนื่องจากมีการสมัครสมาชิก '.$brandName;
        }

        $preheader = ($purpose === 'reset')
                ? "รหัสสำหรับรีเซ็ตรหัสผ่าน (หมดอายุใน {$expiryMin} นาที)"
                : "รหัสสำหรับยืนยันอีเมล (หมดอายุใน {$expiryMin} นาที)";

    // Base palette (pastel browns) — tuned for better dark-mode inversion
    $bgPage     = '#f8f5f2';    // becomes dark grey on Gmail dark mode
    $cardBg     = '#fffaf6';
    $accent     = '#a6733d';    // brand accent (brown)
    $textMain   = '#2b2b2b';    // neutral dark text → inverts to light grey in dark mode
    $muted      = '#666666';
    $border     = '#e6d8cc';
    $otpBg      = '#f3e9e1';
    $otpBorder  = '#d6c2b2';

        $html = <<<HTML
<!doctype html>
<html lang="th" style="background:$bgPage">
    <head>
        <meta charset="utf-8">
        <title>{$subject}</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            @media (max-width:520px){
                .wrap{padding:20px !important}
                .card{border-radius:18px !important}
                .otp{font-size:30px !important;letter-spacing:6px !important;padding:16px 12px !important}
            }
            a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}
        </style>
    </head>
    <body bgcolor="$bgPage" style="margin:0;padding:0;background:$bgPage;font-family:'Prompt',Arial,sans-serif;color:$textMain;font-size:16px;line-height:1.7">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0">{$preheader}</div>
    <table role="presentation" bgcolor="$bgPage" style="width:100%;border-collapse:collapse;background:$bgPage">
            <tr>
                <td align="center" class="wrap" style="padding:32px 24px">
                    <table role="presentation" class="card" bgcolor="$cardBg" style="width:100%;max-width:520px;background:$cardBg;border:1px solid $border;border-radius:24px;box-shadow:0 4px 12px rgba(166,115,61,0.08);overflow:hidden">
                        <tr>
                            <td style="padding:24px 24px 12px 24px;text-align:center">
                                <div style="font-size:22px;font-weight:800;color:$accent;margin-bottom:6px">{$brandEsc}</div>
                                <div style="font-size:15px;color:$muted">{$headline}</div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:10px 28px 6px 28px;font-size:16px;line-height:1.75;color:$textMain">
                                <p style="margin:0 0 12px 0">{$reason}</p>
                                <p style="margin:0 0 16px 0">{$cta}</p>
                                <div style="text-align:center;margin:20px 0 24px 0">
                                    <div class="otp" style="display:inline-block;background:$otpBg;border:1.5px dashed $otpBorder;border-radius:18px;padding:20px 24px;font:800 36px/1 'DM Mono',ui-monospace,Consolas,monospace;letter-spacing:12px;color:$accent">{$otpEsc}</div>
                                </div>
                                <p style="margin:0 0 12px 0;color:$textMain">รหัสดังกล่าวมีอายุ <strong>{$expiryMin} นาที</strong> นับจากเวลาที่ส่ง</p>
                                <p style="margin:0 0 16px 0;font-size:14px;color:$muted">หากคุณไม่ได้ร้องขอ กรุณาเพิกเฉยและอย่าเปิดเผยรหัสนี้กับผู้อื่น</p>
                                <hr style="border:none;border-top:1px solid $border;margin:22px 0 16px 0">
                                <p style="margin:0 0 6px 0;font-size:13px;color:$muted">ต้องการความช่วยเหลือ? ติดต่อ <a href="mailto:$supportEsc" style="color:$accent;text-decoration:underline">$supportEsc</a></p>
                                <p style="margin:0;font-size:11px;color:$muted">ข้อความอัตโนมัติ กรุณาอย่าตอบกลับ</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="background:$bgPage;padding:16px 24px;text-align:center;font-size:12px;color:$muted">
                                © {$year} {$brandEsc} • <a href="$appUrlEsc" style="color:$muted;text-decoration:underline">$appUrlEsc</a>
                            </td>
                        </tr>
                    </table>
                    <div style="font-size:12px;color:$muted;margin-top:12px">หากมองไม่เห็นรหัส โปรดคัดลอก: <strong>{$otpEsc}</strong></div>
                </td>
            </tr>
        </table>
    </body>
</html>
HTML;

        $contactLines = '';
        if ($supportEmail) $contactLines .= "ติดต่อ: {$supportEmail}\n";
        if ($appUrl)       $contactLines .= "เว็บไซต์: {$appUrl}\n";

        $alt = <<<ALT
    {$brandName} — {$headline}

    {$reasonAlt}

    รหัส OTP: {$otp}
    อายุรหัส: {$expiryMin} นาที นับจากเวลาที่ส่ง

    หากไม่ได้ร้องขอ ให้เพิกเฉยต่ออีเมลนี้
    {$contactLines}© {$year} {$brandName}
    ALT;

        return [
                'subject'   => $subject,
                'html'      => $html,
                'alt'       => $alt,
                'preheader' => $preheader,
        ];
}
