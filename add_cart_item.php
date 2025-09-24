<?php
/**
 * add_cart_item.php — เพิ่ม (หรือปรับปรุง nServings) รายการสูตรลงตะกร้า (Cart)
 * =====================================================================================
 * METHOD: POST
 * PARAMS (application/x-www-form-urlencoded หรือ multipart/form-data):
 *   - recipe_id    (int > 0)   : รหัสสูตร
 *   - nServings    (float > 0) : จำนวนเสิร์ฟที่ผู้ใช้ต้องการ (รองรับทศนิยม)
 * BEHAVIOR:
 *   - ใช้ INSERT ... ON DUPLICATE KEY UPDATE → ถ้าเคยมี แค่แก้ nServings (idempotent ต่อชุดค่าเดียวกัน)
 * SECURITY:
 *   - requireLogin() → บังคับผู้ใช้ต้องล็อกอิน
 *   - ตรวจ method เฉพาะ POST ป้องกัน CSRF via simple GET (แนะนำเพิ่ม CSRF token หากรันในเว็บปกติ; แอปมือถืออาจพอได้)
 * VALIDATION:
 *   - ปฏิเสธ recipe_id <= 0 หรือ nServings <= 0
 * PERFORMANCE:
 *   - O(1) single write; ดัชนีที่ต้องมี: UNIQUE(user_id, recipe_id) ในตาราง cart
 * ERROR HANDLING:
 *   - ส่ง message ทั่วไป 'Server error' ไม่เผย SQL internals
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // ใช้ helper

/* ─── Allow only POST ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // จำกัดเฉพาะ POST
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ──────────────────── 1) ตรวจล็อกอิน ──────────────────── */
    $userId = requireLogin(); // ถ้าไม่ล็อกอินจะ throw จบ flow ทันที

    /* ──────────────────── 2) รับ & ตรวจสอบข้อมูล ──────────────────── */
    $recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;      // ใช้ filter_input ป้องกัน type mismatch
    $nServings = filter_input(INPUT_POST, 'nServings',  FILTER_VALIDATE_FLOAT) ?: 0;   // รับเป็น float (เช่น 1.5 เสิร์ฟ)

    if ($recipeId <= 0 || $nServings <= 0) { // เงื่อนไขไม่ผ่าน → 400
        jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'], 400);
    }

    /* ──────────────────── 3) เพิ่มหรืออัปเดตเมนูในตะกร้า ──────────────────── */
    $sql = "
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE nServings = ? -- ถ้ามีคีย์ซ้ำ (user_id+recipe_id) ให้แก้ค่าแทนเพิ่มซ้ำ
    ";
    $params = [$userId, $recipeId, $nServings, $nServings];
    dbExec($sql, $params);

    /* ──────────────────── 4) ส่งผลลัพธ์ ──────────────────── */
    jsonOutput(['success' => true, 'data' => ['message' => 'เพิ่มเข้าตะกร้าเรียบร้อย']]); // ส่ง minimal payload

} catch (Throwable $e) {
    error_log('[add_cart_item] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
