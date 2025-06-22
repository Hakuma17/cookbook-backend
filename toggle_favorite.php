<?php
// toggle_favorite.php — กดถูกใจ / เลิกถูกใจเมนู

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$fav      = filter_input(INPUT_POST, 'favorite', FILTER_VALIDATE_INT) === 1;

if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง'], 400);
}

try {
    if ($fav) {
        dbExec('INSERT IGNORE INTO favorites (user_id, recipe_id) VALUES (?, ?)', [$uid, $recipeId]);
    } else {
        dbExec('DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?', [$uid, $recipeId]);
    }

    jsonOutput(['success' => true]);

} catch (Throwable $e) {
    error_log('[toggle_favorite] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
