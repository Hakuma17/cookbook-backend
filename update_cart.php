<?php
/**
 * update_cart.php — สร้างหรืออัปเดตจำนวนเสิร์ฟ (nServings) ของ recipe ในตะกร้า
 * =====================================================================================
 * METHOD: POST
 * PARAMS:
 *   recipe_id (int > 0)
 *   nServings (float > 0)
 * DIFFERENCE vs add_cart_item.php:
 *   - หน้าที่คล้ายกัน (idempotent insert+update) สามารถรวมได้ในอนาคต
 *   - คงไฟล์ไว้เพื่อความเข้ากันได้ย้อนหลังกับ client ที่เรียก endpoint นี้อยู่แล้ว
 * SECURITY: requireLogin + prepared statement
 * VALIDATION: ปฏิเสธค่าที่ไม่ถูกต้องก่อน query
 * DB: ต้องมี UNIQUE(user_id, recipe_id) สำหรับ ON DUPLICATE KEY
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // จำกัด method
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid       = requireLogin();                                                  // ดึง user_id จาก session
$recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;  // รับเลข recipe
$nServings = filter_input(INPUT_POST, 'nServings', FILTER_VALIDATE_FLOAT) ?: 0.0; // รองรับทศนิยม

if ($recipeId <= 0 || $nServings <= 0) { // ตรวจสอบค่า
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'], 400);
}

try {
    // Idempotent: มี UNIQUE(user_id, recipe_id) ทำให้ ON DUPLICATE KEY ใช้ได้
    dbExec("
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE nServings = VALUES(nServings) -- ปรับเฉพาะ nServings ถ้ามีอยู่แล้ว
    ", [$uid, $recipeId, $nServings]);

    // ส่งข้อมูลกลับให้ FE อัพเดต state ทันที
    jsonOutput([
        'success' => true,
        'message' => 'อัปเดตตะกร้าเรียบร้อย',
        'data'    => [
            'recipe_id' => $recipeId,
            'nServings' => $nServings
        ]
    ]);
} catch (Throwable $e) {
    // ไม่เปิดเผยรายละเอียดภายใน (security) สามารถเพิ่ม logging ภายหลังได้
    jsonOutput([
        'success' => false,
        'message' => 'ไม่สามารถอัปเดตตะกร้าได้',
        'error_code' => 'CART_UPDATE_FAILED'
    ], 500);
}
