<?php
// add_cart_item.php
// เพิ่มสูตรลงในตะกร้า (หรืออัปเดตจำนวนเสิร์ฟ)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    $userId = requireLogin();

    $recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT)    ?: 0;
    $nServings = filter_input(INPUT_POST, 'nServings', FILTER_VALIDATE_FLOAT) ?: 0.0;

    if ($recipeId <= 0 || $nServings <= 0) {
        jsonOutput([
            'success' => false,
            'message' => 'ข้อมูลไม่ถูกต้อง',
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (:uid, :rid, :cnt)
        ON DUPLICATE KEY UPDATE nServings = :cnt
    ");
    $stmt->execute([
        'uid' => $userId,
        'rid' => $recipeId,
        'cnt' => $nServings,
    ]);

    jsonOutput([
        'success' => true,
        'message' => 'เพิ่มเข้าตะกร้าเรียบร้อย',
    ]);
} catch (Exception $e) {
    jsonOutput([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage(),
    ], 500);
}
