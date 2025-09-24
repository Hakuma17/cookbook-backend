<?php
/**
 * remove_cart_item.php — ลบ recipe หนึ่งรายการออกจากตะกร้าของผู้ใช้
 * =====================================================================
 * METHOD: POST
 * PARAMS:
 *   - recipe_id (int > 0)
 * FLOW:
 *   1) ตรวจ method → POST เท่านั้น
 *   2) requireLogin() → รู้ user_id
 *   3) validate recipe_id
 *   4) DELETE เฉพาะแถว user_id + recipe_id (idempotent: ลบซ้ำให้ผลเหมือนเดิม)
 * SECURITY: ใช้ prepared statement (dbExec) + จำกัดเฉพาะ user ปัจจุบัน
 * ERROR: ซ่อนรายละเอียดภายใน ส่งข้อความมาตรฐาน
 * =====================================================================
 */

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // ป้องกัน GET ถูก crawler เรียก
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin();                                    // ถ้าไม่ล็อกอินจะหยุดทันที
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0; // รับ int ปลอดภัย
if ($recipeId <= 0) { // ตรวจค่าก่อน query
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    dbExec("DELETE FROM cart WHERE user_id = ? AND recipe_id = ?", [$uid, $recipeId]); // ลบแบบจำเพาะ

    jsonOutput(['success' => true, 'message' => 'ลบออกจากตะกร้าแล้ว']); // ไม่ต้องคืนข้อมูลอื่น
} catch (Throwable $e) {
    error_log('[remove_cart_item] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
