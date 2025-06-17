<?php
// inc/functions.php

// ─── เริ่ม session ถ้ายังไม่เคยเริ่ม ───────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/// ฟังก์ชัน sanitize ข้อมูล (ป้องกัน XSS)
/// - ตัดช่องว่างหน้า-หลัง และแปลงอักขระพิเศษเป็น HTML entities
function sanitize(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/// ดึง user_id ของผู้ใช้ที่ล็อกอินจาก session
/// - คืนค่า int user_id ถ้ามี
/// - มิฉะนั้นจะคืนค่า null
function getLoggedInUserId(): ?int {
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/// ตรวจสอบสถานะล็อกอิน หากไม่ล็อกอินจะส่ง JSON 401 แล้ว exit
/// - คืนค่า int user_id เมื่อผ่านการตรวจสอบ
function requireLogin(): int {
    $uid = getLoggedInUserId();
    if (! $uid) {
        jsonOutput(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน'], 401);
    }
    return $uid;
}

/// สร้าง base URL สำหรับใช้กับ image path
/// - ตรวจสอบโปรโตคอล HTTP/HTTPS
function getBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');
}

/// ส่ง JSON ตอบกลับพร้อม HTTP status code แล้ว exit
function jsonOutput(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
