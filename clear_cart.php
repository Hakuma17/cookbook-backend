<?php
/**
 * clear_cart.php — ล้าง (DELETE ทั้งหมด) รายการในตะกร้าของผู้ใช้ปัจจุบัน
 * =====================================================================
 * METHOD: POST
 * FLOW:
 *   1) requireLogin → ได้ user_id
 *   2) DELETE FROM cart WHERE user_id = ? (idempotent: รันซ้ำผลเท่าเดิมเมื่อ cart ว่าง)
 * SECURITY: จำกัด method + ผูก user_id เฉพาะคนที่ล็อกอิน
 * PERFORMANCE: O(จำนวนแถวในตะกร้าผู้ใช้) โดยทั่วไปน้อย ไม่กังวล
 * NOTE: ถ้าจะรองรับ partial clear ควรมี endpoint แยก (เช่น clear_cart_items?ids=...)
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // ป้องกัน GET/DELETE เรียกผิด
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ──────────────────── 1) ตรวจล็อกอิน ──────────────────── */
    $userId = requireLogin(); // ถ้าไม่ล็อกอินจะหยุดทันที

    /* ──────────────────── 2) ล้างตะกร้าสูตรอาหาร ──────────────────── */
    dbExec("DELETE FROM cart WHERE user_id = ?", [$userId]); // ลบทุก recipe ของ user นี้

    /* ──────────────────── 3) ส่งผลลัพธ์ ──────────────────── */
    jsonOutput(['success' => true, 'data' => ['message' => 'ล้างตะกร้าเรียบร้อยแล้ว']]); // ไม่จำเป็นต้องส่งรายละเอียดอื่น

} catch (Throwable $e) {
    error_log('[clear_cart] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
