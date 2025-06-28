<?php
// logout.php — ทำลาย PHP-Session

require_once __DIR__.'/inc/functions.php';

if ($_SERVER['REQUEST_METHOD']!=='POST' && $_SERVER['REQUEST_METHOD']!=='GET') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

/* เริ่ม / เรียกคืน session (functions.php จะเปิดให้อัตโนมัติอยู่แล้ว) */
if (session_status() === PHP_SESSION_ACTIVE) {
    // ล้างข้อมูล session
    $_SESSION = [];
    session_unset();

    // ลบ session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // ทำลาย session
    session_destroy();
}

// ปิด session เพื่อป้องกัน lock
session_write_close();

jsonOutput(['success'=>true,'message'=>'Logged out successfully']);
