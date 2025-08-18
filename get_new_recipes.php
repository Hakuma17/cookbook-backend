<?php
// get_new_recipes.php — ดึง “สูตรมาใหม่” 10 รายการล่าสุด
// ส่งกลับ field เพิ่ม: favorite_count, has_allergy
// ★★★ [NEW] ส่ง allergy_groups / allergy_names

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $uid  = getLoggedInUserId();
    $base = getBaseUrl() . '/uploads/recipes';

    $sql = "
        SELECT  r.recipe_id,
                r.name,
                r.image_path,
                r.prep_time,

                /* ★ [FIX] คำนวน favorite_count ด้วย subquery + COALESCE */
                COALESCE((
                    SELECT COUNT(*) FROM favorites f
                     WHERE f.recipe_id = r.recipe_id
                ), 0) AS favorite_count,

                COALESCE(AVG(rv.rating),0) AS average_rating,
                (SELECT COUNT(*) FROM review rv2
                   WHERE rv2.recipe_id = r.recipe_id) AS review_count,
                GROUP_CONCAT(DISTINCT ri.descrip
                             ORDER BY ri.ingredient_id
                             SEPARATOR ', ') AS short_ingredients,
                GROUP_CONCAT(DISTINCT ri.ingredient_id
                             ORDER BY ri.ingredient_id
                             SEPARATOR ',') AS ingredient_ids,

                /* ★ [NEW] เช็ค allergy แบบ “กลุ่ม” เทียบ newcatagory */
                " . ($uid ? "EXISTS (
                     SELECT 1
                       FROM recipe_ingredient ri2
                       JOIN ingredients i2 ON i2.ingredient_id = ri2.ingredient_id
                      WHERE ri2.recipe_id = r.recipe_id
                        AND EXISTS (
                            SELECT 1
                              FROM allergyinfo a
                              JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                             WHERE a.user_id = ?                  -- ★ [FIX] :uid → ?
                               AND ia.newcatagory = i2.newcatagory
                        )
                   )" : "0") . " AS has_allergy,

                /* ★★★ [NEW] กลุ่มที่ชน */
                " . ($uid ? "(SELECT GROUP_CONCAT(DISTINCT TRIM(i2.newcatagory) SEPARATOR ',')
                                FROM recipe_ingredient x
                                JOIN ingredients i2 ON i2.ingredient_id = x.ingredient_id
                               WHERE x.recipe_id = r.recipe_id
                                 AND EXISTS (
                                   SELECT 1
                                     FROM allergyinfo a
                                     JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                                    WHERE a.user_id = ?              -- ★ [FIX] :uid → ?
                                      AND ia.newcatagory = i2.newcatagory
                                 )
                             )" : "NULL") . " AS allergy_groups,

                /* ★★★ [NEW] ชื่อที่เอาไว้ขึ้นชิป */
                " . ($uid ? "(SELECT GROUP_CONCAT(DISTINCT COALESCE(ia2.display_name, ia2.name) SEPARATOR ',')
                                FROM allergyinfo a2
                                JOIN ingredients ia2 ON ia2.ingredient_id = a2.ingredient_id
                               WHERE a2.user_id = ?                -- ★ [FIX] :uid → ?
                                 AND TRIM(ia2.newcatagory) IN (
                                   SELECT TRIM(i3.newcatagory)
                                     FROM recipe_ingredient y
                                     JOIN ingredients i3 ON i3.ingredient_id = y.ingredient_id
                                    WHERE y.recipe_id = r.recipe_id
                                 )
                             )" : "NULL") . " AS allergy_names

        FROM      recipe            r
        LEFT JOIN review            rv ON rv.recipe_id  = r.recipe_id
        LEFT JOIN recipe_ingredient ri ON ri.recipe_id = r.recipe_id
        GROUP BY  r.recipe_id
        ORDER BY  r.created_at DESC
        LIMIT     10
    ";

    // ★ [FIX] ใช้ positional params ให้ตรงจำนวน ? ข้างบน (3 จุด)
    $rows = $uid ? dbAll($sql, [ $uid, $uid, $uid ]) : dbAll($sql);

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'recipe_id'         => (int)$row['recipe_id'],
            'name'              => $row['name'],
            'prep_time'         => $row['prep_time'] ? (int)$row['prep_time'] : null,
            'favorite_count'    => (int)$row['favorite_count'],
            'average_rating'    => (float)$row['average_rating'],
            'review_count'      => (int)$row['review_count'],
            'short_ingredients' => $row['short_ingredients'] ?? '',
            'has_allergy'       => (bool)$row['has_allergy'],

            // เดิม: default_recipe.jpg
            // ★ [NEW] ให้สอดคล้องกับ endpoint อื่น ๆ ที่ส่ง .png (ถ้าบนเซิร์ฟเวอร์ยังมีแต่ .jpg ให้ใส่ไฟล์ .png เพิ่ม หรือเปลี่ยนชื่อให้ตรงกันทั้งระบบ)
            'image_url'         => $base . '/' . ($row['image_path'] ?: 'default_recipe.png'),

            'ingredient_ids'    => array_filter(array_map('intval',
                                        explode(',', $row['ingredient_ids'] ?? ''))),
            /* ★★★ [NEW] */
            'allergy_groups'    => array_values(array_filter(array_map('trim',
                                      explode(',', (string)($row['allergy_groups'] ?? ''))))),
            'allergy_names'     => array_values(array_filter(array_map('trim',
                                      explode(',', (string)($row['allergy_names'] ?? ''))))),
        ];
    }

    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_new_recipes] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
