<?php
// inc/functions.php

// เริ่ม session ถ้ายังไม่เคยเริ่ม (แต่ API แต่ละไฟล์มักเรียก session_start เอง)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}
