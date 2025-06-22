<?php
// add_cart_item.php — เพิ่ม/อัปเดตเมนูในตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // ใช้ helper

/* ─── Allow only POST ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ──────────────────── 1) ตรวจล็อกอิน ──────────────────── */
    $userId = requireLogin();

    /* ──────────────────── 2) รับ & ตรวจสอบข้อมูล ──────────────────── */
    $recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
    $nServings = filter_input(INPUT_POST, 'nServings',  FILTER_VALIDATE_FLOAT) ?: 0;

    if ($recipeId <= 0 || $nServings <= 0) {
        jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'], 400);
    }

    /* ──────────────────── 3) เพิ่มหรืออัปเดตเมนูในตะกร้า ──────────────────── */
    $sql = "
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE nServings = ?
    ";
    $params = [$userId, $recipeId, $nServings, $nServings];
    dbExec($sql, $params);

    /* ──────────────────── 4) ส่งผลลัพธ์ ──────────────────── */
    jsonOutput(['success' => true, 'data' => ['message' => 'เพิ่มเข้าตะกร้าเรียบร้อย']]);

} catch (Throwable $e) {
    error_log('[add_cart_item] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
