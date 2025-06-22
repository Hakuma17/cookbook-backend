<?php
// remove_cart_item.php — ลบเมนูออกจากตะกร้า

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    dbExec("DELETE FROM cart WHERE user_id = ? AND recipe_id = ?", [$uid, $recipeId]);

    jsonOutput(['success' => true, 'message' => 'ลบออกจากตะกร้าแล้ว']);
} catch (Throwable $e) {
    error_log('[remove_cart_item] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
