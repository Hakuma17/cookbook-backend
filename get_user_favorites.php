<?php
// get_user_favorites.php — คืนรายการสูตรโปรดของผู้ใช้

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ──────────────────── 1) ตรวจล็อกอิน ──────────────────── */
    $uid  = requireLogin();
    $base = getBaseUrl() . '/uploads/recipes';

    /* ──────────────────── 2) ดึงสูตรโปรด ──────────────────── */
    $rows = dbAll("
        SELECT r.recipe_id,
               r.name,
               r.image_path,
               r.prep_time,
               r.average_rating,
               (
                   SELECT COUNT(*)
                   FROM review
                   WHERE recipe_id = r.recipe_id
               ) AS review_count
        FROM favorites  AS f
        JOIN recipe     AS r ON r.recipe_id = f.recipe_id
        WHERE f.user_id = ?
    ", [$uid]);

    /* ──────────────────── 3) สร้างผลลัพธ์ ──────────────────── */
    $data = [];

    foreach ($rows as $r) {
        // เช็คมีส่วนผสมที่แพ้หรือไม่
        $hasAllergy = dbVal("
            SELECT COUNT(*)
            FROM recipe_ingredient
            WHERE recipe_id = ?
              AND ingredient_id IN (
                  SELECT ingredient_id FROM allergyinfo WHERE user_id = ?
              )
        ", [$r['recipe_id'], $uid]) > 0;

        $data[] = [
            'recipe_id'      => (int) $r['recipe_id'],
            'name'           => $r['name'],
            'prep_time'      => $r['prep_time'] !== null ? (int) $r['prep_time'] : null,
            'average_rating' => (float) $r['average_rating'],
            'review_count'   => (int) $r['review_count'],
            'image_url'      => $base . '/' . basename($r['image_path'] ?: 'default_recipe.png'),
            'has_allergy'    => $hasAllergy,
        ];
    }

    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_user_favorites] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
