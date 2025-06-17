<?php
// update_cart.php
// เพิ่มหรืออัปเดตจำนวนเสิร์ฟของสูตรอาหารในตะกร้า

require_once __DIR__ . '/inc/config.php';    // เชื่อมต่อฐานข้อมูล ($pdo)
require_once __DIR__ . '/inc/functions.php'; // ฟังก์ชันช่วยเหลือ (requireLogin, jsonOutput, ฯลฯ)

try {
    // 1) ตรวจสอบล็อกอิน และดึง user_id
    $userId = requireLogin();

    // 2) รับค่า POST และ validate
    $recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT)    ?: 0;
    $nServings = filter_input(INPUT_POST, 'nServings', FILTER_VALIDATE_FLOAT) ?: 0.0;

    if ($recipeId <= 0 || $nServings <= 0) {
        // ถ้าข้อมูลไม่ถูกต้อง ส่ง 400 Bad Request
        jsonOutput([
            'success' => false,
            'message' => 'ข้อมูลไม่ถูกต้อง',
            'data'    => null
        ], 400);
    }

    // 3) Upsert: ถ้ายังไม่มีใน cart ให้ INSERT, ถ้ามีแล้วให้ UPDATE
    $sql = "
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (:uid, :rid, :cnt)
        ON DUPLICATE KEY UPDATE nServings = :cnt
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'uid' => $userId,
        'rid' => $recipeId,
        'cnt' => $nServings,
    ]);

    // 4) ส่งผลลัพธ์ success พร้อม data กลับ
    jsonOutput([
        'success' => true,
        'message' => 'อัปเดตตะกร้าเรียบร้อย',
        'data'    => [
            'recipe_id'  => (int)$recipeId,
            'nServings'  => (float)$nServings,
        ],
    ]);

} catch (Exception $e) {
    // กรณีเกิดข้อผิดพลาด ส่ง status 500 พร้อมข้อความ error
    jsonOutput([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage(),
        'data'    => null
    ], 500);
}
