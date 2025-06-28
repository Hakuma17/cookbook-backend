<?php
// toggle_favorite.php — กดถูกใจ / เลิกถูกใจเมนู (อัปเดต favorite_count ด้วย)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

try {
    /* 1) Allow only POST */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    /* 2) ต้องล็อกอิน */
    $uid = requireLogin();

    /* 3) รับค่า */
    $recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
    $fav      = filter_input(INPUT_POST, 'favorite', FILTER_VALIDATE_INT) === 1;
    if ($recipeId <= 0) {
        jsonOutput(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง'], 400);
    }

    /* 4) INSERT / DELETE ใน favorites */
    if ($fav) {
        dbExec(
            'INSERT IGNORE INTO favorites (user_id, recipe_id) VALUES (?, ?)',
            [$uid, $recipeId]
        );
    } else {
        dbExec(
            'DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?',
            [$uid, $recipeId]
        );
    }

    /* 5) อัปเดตยอดรวม favorite_count */
    $cnt = dbVal(
        'SELECT COUNT(*) FROM favorites WHERE recipe_id = ?',
        [$recipeId]
    );
    // *** เปลี่ยนชื่อ table ตรงนี้ให้ตรงของคุณ: `recipe` หรือ `recipes`
    dbExec(
        'UPDATE recipe SET favorite_count = ? WHERE recipe_id = ?',
        [$cnt, $recipeId]
    );

    /* 6) ตอบกลับ (ส่ง count กลับด้วย) */
    jsonOutput([
        'success'        => true,
        'favorite_count' => (int)$cnt,
        'is_favorite'    => $fav
    ]);

} catch (Throwable $e) {
    error_log('[toggle_favorite] ' . $e->getMessage());
    if (strpos($e->getMessage(), 'LoginRequired') !== false) {
        jsonOutput(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], 401);
    }
    jsonOutput([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ], 500);
}
