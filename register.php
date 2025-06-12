<?php
// API สำหรับ Flutter: รับ POST (email, password, confirm_password)
// → สมัครสมาชิกลงตาราง `user` (Argon2id hashing) → คืนค่า JSON { success, errors? }

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

// 1) ยืนยัน Method POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'errors'  => ['Method not allowed']
    ]);
    exit;
}

$errors = [];

// 2) รับค่าและ sanitize
$email           = sanitize($_POST['email']            ?? '');
$password        =             $_POST['password']         ?? '';
$confirmPassword =             $_POST['confirm_password'] ?? '';

// 3) ตรวจสอบข้อผิดพลาดเบื้องต้น
if (empty($email) || empty($password) || empty($confirmPassword)) {
    $errors[] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
} elseif ($password !== $confirmPassword) {
    $errors[] = 'รหัสผ่านและการยืนยันไม่ตรงกัน';
} elseif (strlen($password) < 8) {
    $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
}

// 4) ถ้ายังไม่มี error ให้เช็คซ้ำอีเมล
if (empty($errors)) {
    $stmt = $pdo->prepare('SELECT user_id FROM `user` WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $errors[] = 'อีเมลนี้ถูกใช้งานแล้ว';
    }
}

// 5) ถ้าทุกอย่างผ่าน ให้ hash รหัสผ่านด้วย Argon2id และ INSERT
if (empty($errors)) {
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $ins = $pdo->prepare(
        'INSERT INTO `user` (`email`,`password`,`created_at`) VALUES (?, ?, NOW())'
    );
    $ok = $ins->execute([$email, $hash]);

    if ($ok) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        $errors[] = 'เกิดข้อผิดพลาดในการสมัคร กรุณาลองใหม่อีกครั้ง';
    }
}

// 6) ถ้ามี error ให้คืน JSON พร้อมรหัสสถานะ 400
http_response_code(400);
echo json_encode([
    'success' => false,
    'errors'  => $errors
]);
exit;
