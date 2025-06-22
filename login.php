<?php
// email-password login

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

$email = sanitize($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
    respond(false, ['message' => 'กรุณาระบุอีเมลและรหัสผ่าน'], 400);
}

try {
    $row = dbOne('SELECT user_id, password FROM user WHERE email = ? LIMIT 1', [$email]);

    if (!$row) {
        respond(false, ['message' => 'ไม่พบบัญชีนี้'], 401);
    }

    if (!password_verify($pass, $row['password'] ?? '')) {
        respond(false, ['message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
    }

    $uid = (int)$row['user_id'];
    $_SESSION['user_id'] = $uid;

    $info = dbOne('SELECT profile_name, path_imgProfile FROM user WHERE user_id = ?', [$uid]) ?: [];

    respond(true, [
        'user_id'         => $uid,
        'email'           => $email,
        'profile_name'    => $info['profile_name']    ?? '',
        'path_imgProfile' => $info['path_imgProfile'] ?? ''
    ]);

} catch (Throwable $e) {
    error_log('[login] ' . $e->getMessage());
    respond(false, ['message' => 'Server error'], 500);
}
