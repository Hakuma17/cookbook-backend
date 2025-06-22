<?php
// update_cart.php — เพิ่มหรือแก้จำนวนเสิร์ฟในตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid       = requireLogin();
$recipeId  = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$nServings = filter_input(INPUT_POST, 'nServings', FILTER_VALIDATE_FLOAT) ?: 0.0;

if ($recipeId <= 0 || $nServings <= 0) {
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'], 400);
}

try {
    dbExec("
        INSERT INTO cart (user_id, recipe_id, nServings)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE nServings = VALUES(nServings)
    ", [$uid, $recipeId, $nServings]);

    jsonOutput([
        'success' => true,
        'message' => 'อัปเดตตะกร้าเรียบร้อย',
        'data' => [
            'recipe_id'  => $recipeId,
            'nServings'  => $nServings
        ]
    ]);
} catch (Throwable $e) {
    error_log('[update_cart] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
