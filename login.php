<?php
// login.php — API ล็อกอินอีเมล/รหัส ผ่าน POST → คืน JSON พร้อมข้อมูลผู้ใช้

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
function respond(bool $ok, array $data = [], int $code = 200) {
    if (ob_get_length()) ob_clean();      // ล้าง buffer ที่หลุดมาก่อนหน้า
    http_response_code($code);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'ล็อกอินสำเร็จ' : ($data['message'] ?? 'ไม่สามารถล็อกอินได้'),
        'data'    => $ok ? $data : (object)[],
    ]);
    exit;
}

// 5) รับเฉพาะ POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

// 6) รับค่าแล้ว sanitize
$email = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$pass  = $_POST['password'] ?? '';

// 7) ตรวจ input
if ($email === '' || $pass === '') {
    respond(false, ['message' => 'กรุณาระบุอีเมลและรหัสผ่าน'], 400);
}

try {
    // 8) ดึงบัญชีผู้ใช้
    $stmt = $pdo->prepare('SELECT user_id, password FROM user WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // 9) เช็ก user + password
    if (!$user || !password_verify($pass, $user['password'])) {
        respond(false, ['message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
    }

    $userId = (int) $user['user_id'];

    // 10) เริ่ม session
    session_start();
    $_SESSION['user_id'] = $userId;

    // 11) ดึงชื่อและรูปจากฐานข้อมูล
    $stmt = $pdo->prepare('SELECT profile_name, path_imgProfile FROM user WHERE user_id = ?');
    $stmt->execute([$userId]);
    $info = $stmt->fetch();

    // 12) ตอบกลับพร้อมข้อมูล
    respond(true, [
        'user_id'         => $userId,
        'email'           => $email,
        'profile_name'    => $info['profile_name'] ?? '',
        'path_imgProfile' => $info['path_imgProfile'] ?? '',
    ]);
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    respond(false, ['message' => 'Server error'], 500);
}
