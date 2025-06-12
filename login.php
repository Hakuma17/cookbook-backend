<?php
// login.php — API ล็อกอินอีเมล/รหัส ผ่าน POST → คืน JSON เพียว ๆ

// 1) เริ่ม buffer และปิดโชว์ error แบบ HTML
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

// 2) ตั้ง header เป็น JSON
header('Content-Type: application/json; charset=UTF-8');

// 3) โหลด config + helper
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

// 4) ฟังก์ชันช่วยตอบ JSON แล้วจบสคริปต์
function respond(bool $ok, string $msg = '', int $code = 200) {
    if (ob_get_length()) ob_clean();      // ล้าง buffer ที่หลุดมาก่อนหน้า
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

// 5) รับเฉพาะ POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed', 405);
}

// 6) รับค่าแล้ว sanitize
$email = htmlspecialchars(trim($_POST['email']    ?? ''), ENT_QUOTES, 'UTF-8');
$pass  =             $_POST['password'] ?? '';

// 7) ตรวจ input
if ($email === '' || $pass === '') {
    respond(false, 'กรุณาระบุอีเมลและรหัสผ่าน', 400);
}

try {
    // 8) ดึงบัญชี
    $stmt = $pdo->prepare('SELECT user_id, password FROM user WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // 9) เช็ก user + password
    if (!$user || !password_verify($pass, $user['password'])) {
        respond(false, 'อีเมลหรือรหัสผ่านไม่ถูกต้อง', 401);
    }

    // 10) สำเร็จ
    respond(true);
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    respond(false, 'Server error', 500);
}
