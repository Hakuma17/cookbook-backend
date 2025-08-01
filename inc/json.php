<?php
// inc/json.php

/**
 * ส่ง JSON และ HTTP status code จาก payload แล้ว exit
 */
function jsonOutput(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    if (ob_get_length()) { ob_clean(); }   // ← ล้าง output เดิมออกก่อน
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}


/**
 * ส่ง JSON error ที่เป็นโครงสร้าง {success: false, message, data: {}} แล้ว exit
 */
function jsonError(string $message = 'เกิดข้อผิดพลาด', int $statusCode = 400): void {
    jsonOutput([
        'success' => false,
        'message' => $message,
        'data'    => (object)[],
    ], $statusCode);
}