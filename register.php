<?php
// register.php — สมัครสมาชิก (email + password + username)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'errors' => ['Method not allowed']], 405);
}

$email    = sanitize($_POST['email']            ?? '');
$pass     =            $_POST['password']         ?? '';
$confirm  =            $_POST['confirm_password'] ?? '';
$userName = sanitize($_POST['username']         ?? '');

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
    $errs[] = 'รหัสผ่าน ≥ 8 ตัว';
}

if (!$errs) {
    $dup = dbVal("SELECT 1 FROM user WHERE email = ? LIMIT 1", [$email]);
    if ($dup) {
        $errs[] = 'อีเมลนี้มีอยู่แล้ว';
    }
}

if ($errs) {
    jsonOutput(['success' => false, 'errors' => $errs], 400);
}

$hash = password_hash($pass, PASSWORD_ARGON2ID);
$ok   = dbExec("
    INSERT INTO user (email, password, profile_name, created_at)
    VALUES (?, ?, ?, NOW())
", [$email, $hash, $userName]);

$ok
    ? jsonOutput(['success' => true])
    : jsonOutput(['success' => false, 'errors' => ['สมัครไม่สำเร็จ ลองใหม่']], 500);
