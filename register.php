<?php
// register.php — สมัครสมาชิก (email + password + username)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

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

// ─────── บันทึกลงฐานข้อมูล ───────
$hash = password_hash($pass, PASSWORD_ARGON2ID);
$ok   = dbExec("
    INSERT INTO user (email, password, profile_name, created_at)
    VALUES (?, ?, ?, NOW())
", [$email, $hash, $userName]);

// ─────── ส่งผลลัพธ์กลับ ───────
$ok
    ? jsonOutput(['success' => true])
    : jsonOutput(['success' => false, 'errors' => ['สมัครไม่สำเร็จ ลองใหม่']], 500);
