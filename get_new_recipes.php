<?php
// get_new_recipes.php — ดึง “สูตรมาใหม่” 10 รายการล่าสุด
// ส่งกลับ field เพิ่ม:  favorite_count  (รวมยอดถูกใจปัจจุบัน)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

/* ── 1) Allow-only GET ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ── 2) ข้อมูลผู้ใช้ (ถ้ามี) ─────────────────────────────────── */
    $uid  = getLoggedInUserId();          // null ถ้า guest
    $base = getBaseUrl() . '/uploads/recipes';

    /* ── 3) SQL ดึงเมนูล่าสุด + meta ─────────────────────────────── */
    $sql = "
        SELECT  r.recipe_id,
                r.name,
                r.image_path,
                r.prep_time,
                r.favorite_count,                           -- ★ เพิ่ม
                COALESCE(AVG(rv.rating),0)          AS average_rating,
                (SELECT COUNT(*) FROM review rv2
                  WHERE rv2.recipe_id = r.recipe_id) AS review_count,

                /* วัตถุดิบย่อ (ชื่อ) */
                GROUP_CONCAT(DISTINCT ri.descrip
                             ORDER BY ri.ingredient_id
                             SEPARATOR ', ')            AS short_ingredients,
                /* id วัตถุดิบทุกตัว */
                GROUP_CONCAT(DISTINCT ri.ingredient_id
                             ORDER BY ri.ingredient_id
                             SEPARATOR ',')             AS ingredient_ids,

                /* มีวัตถุดิบที่ผู้ใช้แพ้หรือไม่ (1/0) */
                " . ($uid ? "EXISTS (
                        SELECT 1
                          FROM recipe_ingredient ri2
                          JOIN allergyinfo a
                                ON a.ingredient_id = ri2.ingredient_id
                         WHERE ri2.recipe_id = r.recipe_id
                           AND a.user_id      = :uid
                    )" : "0") . "                      AS has_allergy
        FROM      recipe               r
        LEFT JOIN review               rv ON rv.recipe_id  = r.recipe_id
        LEFT JOIN recipe_ingredient    ri ON ri.recipe_id  = r.recipe_id
        GROUP BY  r.recipe_id
        ORDER BY  r.created_at DESC
        LIMIT     10
    ";

    /* ── 4) query DB  ─────────────────────────────────────────────── */
    $rows = $uid
        ? dbAll($sql, ['uid' => $uid])
        : dbAll($sql);

    /* ── 5) แปลงให้อยู่ในรูป array-friendly ─────────────────────── */
    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'recipe_id'         => (int)$row['recipe_id'],
            'name'              => $row['name'],
            'prep_time'         => $row['prep_time'] ? (int)$row['prep_time'] : null,
            'favorite_count'    => (int)$row['favorite_count'],   // ★ ใหม่
            'average_rating'    => (float)$row['average_rating'],
            'review_count'      => (int)$row['review_count'],
            'short_ingredients' => $row['short_ingredients'] ?? '',
            'has_allergy'       => (bool)$row['has_allergy'],
            'image_url'         => $base . '/' .
                                   ($row['image_path'] ?: 'default_recipe.jpg'),
            'ingredient_ids'    => array_filter(
                                      array_map('intval',
                                        explode(',', $row['ingredient_ids'] ?? '')
                                      )
                                  ),
        ];
    }

    /* ── 6) response ─────────────────────────────────────────────── */
    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_new_recipes] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
