<?php // logout.php — ออกจากระบบ / ทำลาย Session ปัจจุบัน

require_once __DIR__.'/inc/functions.php'; // โหลดฟังก์ชันพื้นฐาน (มี session_start แล้ว)

// อนุญาตทั้ง GET และ POST (เผื่อ FE ใช้วิธีกดลิงก์หรือ fetch)
if ($_SERVER['REQUEST_METHOD']!=='POST' && $_SERVER['REQUEST_METHOD']!=='GET') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

// ถ้า session เปิดอยู่ → ทำขั้นตอนทำลาย
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];          // ล้างตัวแปรในหน่วยความจำ
    session_unset();         // ล้างตัวแปรระบบ

    // ลบคุกกี้ session (ตั้งหมดอายุย้อนหลัง)
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();       // ทำลายไฟล์ session จริง

    // ป้องกัน session fixation: เปิด session ใหม่สั้น ๆ แล้ว regen id ทิ้ง
    if (function_exists('session_start') && session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true); // ออกใหม่ ID
        }
        $_SESSION = [];       // ให้แน่ใจว่าไม่มีข้อมูลค้าง
        session_write_close();
    }
}

session_write_close();        // ปิด handle ป้องกัน lock

jsonOutput(['success'=>true,'message'=>'Logged out successfully']); // ส่งผลลัพธ์กลับ FE
