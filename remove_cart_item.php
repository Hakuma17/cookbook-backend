<?php
// remove_cart_item.php
// ลบรายการออกจากตะกร้า

require_once __DIR__ . '/inc/config.php';    // เชื่อมต่อฐานข้อมูล ($pdo)
require_once __DIR__ . '/inc/functions.php'; // ฟังก์ชันช่วยเหลือ (requireLogin, jsonOutput, ฯลฯ)

try {
    // 1) ตรวจสอบล็อกอิน และดึง user_id
    $userId = requireLogin();

    // 2) รับค่า POST recipe_id และ validate
    $recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
    if ($recipeId <= 0) {
        // ต้องระบุ recipe_id ให้ถูกต้อง
        jsonOutput([
            'success' => false,
            'message' => 'ต้องระบุ recipe_id'
        ], 400);
    }

    // 3) ลบแถวใน cart ตาม user_id + recipe_id
    $stmt = $pdo->prepare("
        DELETE FROM cart 
        WHERE user_id = :uid 
          AND recipe_id = :rid
    ");
    $stmt->execute([
        'uid' => $userId,
        'rid' => $recipeId,
    ]);

    // 4) ส่งผลลัพธ์ success
    jsonOutput([
        'success' => true,
        'message' => 'ลบออกจากตะกร้าเรียบร้อย'
    ]);

} catch (Exception $e) {
    // กรณีเกิดข้อผิดพลาด ส่ง 500 Internal Server Error พร้อมข้อความ
    jsonOutput([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], 500);
}
