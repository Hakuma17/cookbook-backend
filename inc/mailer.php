
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
