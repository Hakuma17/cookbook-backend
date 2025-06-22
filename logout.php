<?php
// logout.php — ทำลาย PHP-Session

require_once __DIR__.'/inc/functions.php';

if ($_SERVER['REQUEST_METHOD']!=='POST' && $_SERVER['REQUEST_METHOD']!=='GET') {
    jsonOutput(['success'=>false,'message'=>'Method not allowed'],405);
}

/* เริ่ม / เรียกคืน session (functions.php จะเปิดให้อัตโนมัติอยู่แล้ว) */
$_SESSION = [];
session_destroy();

jsonOutput(['success'=>true,'message'=>'Logged out successfully']);
