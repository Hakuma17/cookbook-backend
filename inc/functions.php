<?php
// inc/functions.php

// เริ่ม session ถ้ายังไม่เคยเริ่ม
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/// ฟังก์ชัน sanitize ข้อมูล (ป้องกัน XSS)
function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/// ดึง user_id ของผู้ใช้ที่ล็อกอินจาก session
/// - คืนค่า user_id (int) ถ้ามีใน session
/// - มิฉะนั้นคืน null
function getLoggedInUserId() {
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}
