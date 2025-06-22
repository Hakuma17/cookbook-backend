<?php
// verify_otp.php — ยืนยัน OTP พร้อม rate-limit

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

const MAX_ATTEMPT = 5;
const LOCK_SEC    = 600;   // 10 นาที

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

$email = sanitize($_POST['email'] ?? '');
$otp   = sanitize($_POST['otp']   ?? '');

if ($email === '' || $otp === '') {
    jsonOutput(['success'=>false,'message'=>'กรุณากรอกอีเมลและ OTP'],400);
}

/* ดึง record */
$rec = dbOne("
    SELECT otp, otp_expires_at, attempts, lock_until
    FROM user_otp WHERE email=? LIMIT 1
", [$email]);

if (!$rec) {
    jsonOutput(['success'=>false,'message'=>'ยังไม่เคยขอ OTP'],404);
}

/* ถูกล็อก? */
if ($rec['lock_until'] && time() < strtotime($rec['lock_until'])) {
    $wait = strtotime($rec['lock_until']) - time();
    jsonOutput(['success'=>false,'message'=>"บัญชีล็อก {$wait} วินาที"],423);
}

/* OTP ผิด */
if ($rec['otp'] !== $otp) {
    $att = $rec['attempts'] + 1;
    if ($att >= MAX_ATTEMPT) {
        $until = date('Y-m-d H:i:s', time() + LOCK_SEC);
        dbExec("UPDATE user_otp SET attempts=0, lock_until=? WHERE email=?", [$until, $email]);
        jsonOutput(['success'=>false,'message'=>'OTP ผิดเกินกำหนด – ล็อก 10 นาที'],429);
    }

    dbExec("UPDATE user_otp SET attempts=? WHERE email=?", [$att, $email]);
    $left = MAX_ATTEMPT - $att;
    jsonOutput(['success'=>false,'message'=>"OTP ไม่ถูกต้อง (เหลือ {$left} ครั้ง)"],401);
}

/* หมดอายุ */
if (time() > strtotime($rec['otp_expires_at'])) {
    dbExec("DELETE FROM user_otp WHERE email=?", [$email]);
    jsonOutput(['success'=>false,'message'=>'OTP หมดอายุแล้ว'],410);
}

/* สำเร็จ – รีเซ็ตรายการ & ตั้ง session */
dbExec("UPDATE user_otp SET attempts=0, lock_until=NULL WHERE email=?", [$email]);

$_SESSION['verified_email'] = $email;
$_SESSION['verified_at']    = time();

jsonOutput(['success'=>true,'message'=>'OTP ถูกต้อง']);
