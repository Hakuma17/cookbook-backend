<?php
/**
 * get_popular_recipes.php — ดึงสูตร "ยอดนิยม" 10 รายการ (เรียงจาก favorite_count สูงสุด รองด้วย average_rating)
 * =====================================================================================
 * CRITERIA:
 *   - popularity หลัก = จำนวนผู้กด favorite (favorite_count)
 *   - tie-breaker = average_rating (DESC)
 *   - fallback order (ในกรณีคะแนนเท่ากันทั้งสอง) = ลำดับที่ DB คืน (ไม่มีระบุ created_at)
 *
 * RESPONSE FIELDS (ออบเจ็กต์หนึ่งรายการ):
 *   recipe_id, name, prep_time|null,
 *   favorite_count, average_rating, review_count,
 *   short_ingredients (string), ingredient_ids[int...],
 *   image_url, has_allergy (bool), allergy_groups[string...], allergy_names[string...]
 *
 * ALLERGY (เหมือน endpoint ใหม่อื่น ๆ):
 *   - เทียบด้วย newcatagory group → ลด false negative กรณีผู้ใช้แพ้ทั้งหมวด
 *
 * PERFORMANCE NOTES:
 *   - Subqueries COUNT/AVG per row → ถ้า table ใหญ่มากควร materialize (เช่น recipe.favorite_count_cached)
 *   - ดัชนีแนะนำ: favorites(recipe_id), review(recipe_id), recipe(created_at) แม้ไม่ได้ใช้ order แต่ช่วย join/subquery
 *   - GROUP_CONCAT มีเพดานความยาว → ถ้าวัตถุดิบมากอาจ truncate (ปรับ group_concat_max_len หากจำเป็น)
 *
 * IMAGE FALLBACK STRATEGY:
 *   - ตรวจว่ามี default_recipe.png ถ้าไม่มีลอง .jpg แล้วค่อย fallback เป็น .png
 *
 * EXTENSIBILITY / TODO:
 *   - รองรับ ?limit= / ?page= เพิ่มเติมถ้าต้องการ endless scroll
 *   - อาจเพิ่ม metric อื่น (เช่น review_count weight) = recommended โหมดแยก
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

/* ── 1) Allow-only GET ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $uid  = getLoggedInUserId();
    $base = getBaseUrl() . '/uploads/recipes';

    // ★ [NEW] ตรวจไฟล์ default ให้ตรงกับของจริง (.png มาก่อน ถ้าไม่มีค่อยใช้ .jpg)
    $uploadsDir      = __DIR__ . '/uploads/recipes';
    $defaultImageRel = file_exists($uploadsDir . '/default_recipe.png')
        ? 'default_recipe.png'
        : (file_exists($uploadsDir . '/default_recipe.jpg')
            ? 'default_recipe.jpg'
            : 'default_recipe.png'); // fallback ท้ายสุด

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

                COALESCE(AVG(rv.rating),0)  AS average_rating,
                (SELECT COUNT(*) FROM review rv2
                  WHERE rv2.recipe_id = r.recipe_id) AS review_count,

                /* วัตถุดิบย่อ */
                GROUP_CONCAT(DISTINCT ri.descrip
                             ORDER BY ri.ingredient_id
                             SEPARATOR ', ') AS short_ingredients,
                GROUP_CONCAT(DISTINCT ri.ingredient_id
                             ORDER BY ri.ingredient_id
                             SEPARATOR ',')  AS ingredient_ids,

                /* [NEW] ผู้ใช้แพ้ไหม? เทียบแบบ “กลุ่ม” ด้วย newcatagory */
                " . ($uid ? "EXISTS (
                        SELECT 1
                          FROM recipe_ingredient ri2
                          JOIN ingredients i2 ON i2.ingredient_id = ri2.ingredient_id
                         WHERE ri2.recipe_id = r.recipe_id
                           AND EXISTS (
                               SELECT 1
                                 FROM allergyinfo a
                                 JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                                WHERE a.user_id = ?                -- ★ [FIX] เปลี่ยน :uid → ?
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
                                    WHERE a.user_id = ?            -- ★ [FIX] :uid → ?
                                      AND ia.newcatagory = i2.newcatagory
                                 )
                             )" : "NULL") . " AS allergy_groups,

                /* ★★★ [NEW] ชื่อที่เอาไว้ขึ้นชิป */
                " . ($uid ? "(SELECT GROUP_CONCAT(DISTINCT COALESCE(ia2.display_name, ia2.name) SEPARATOR ',')
                                FROM allergyinfo a2
                                JOIN ingredients ia2 ON ia2.ingredient_id = a2.ingredient_id
                               WHERE a2.user_id = ?              -- ★ [FIX] :uid → ?
                                 AND TRIM(ia2.newcatagory) IN (
                                   SELECT TRIM(i3.newcatagory)
                                     FROM recipe_ingredient y
                                     JOIN ingredients i3 ON i3.ingredient_id = y.ingredient_id
                                    WHERE y.recipe_id = r.recipe_id
                                 )
                             )" : "NULL") . " AS allergy_names

        FROM      recipe               r
        LEFT JOIN review               rv ON rv.recipe_id  = r.recipe_id
        LEFT JOIN recipe_ingredient    ri ON ri.recipe_id  = r.recipe_id
        GROUP BY  r.recipe_id
        /* ★ [FIX] จัดอันดับด้วย favorite_count (จาก subquery) รองลงมาคือ average_rating */
        ORDER BY  favorite_count DESC, average_rating DESC
        LIMIT     10
    ";

    // ★ [FIX] ใช้ positional params: ถ้ามี $uid ให้ส่งสามครั้งตามจำนวน ? ข้างบน
    $rows = $uid
        ? dbAll($sql, [ $uid, $uid, $uid ])
        : dbAll($sql);

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
            'ingredient_ids'    => array_filter(array_map('intval',
                                      explode(',', $row['ingredient_ids'] ?? ''))),
            'has_allergy'       => (bool)$row['has_allergy'],

            // ★ [NEW] ใช้รูป default ให้ตรงกับไฟล์จริงที่มีในโฟลเดอร์ uploads/recipes
            'image_url'         => $base . '/' . ($row['image_path'] ?: $defaultImageRel),

            /* ★★★ [NEW] */
            'allergy_groups'    => array_values(array_filter(array_map('trim',
                                      explode(',', (string)($row['allergy_groups'] ?? ''))))),
            'allergy_names'     => array_values(array_filter(array_map('trim',
                                      explode(',', (string)($row['allergy_names'] ?? ''))))),
        ];
    }

    /* ── 5) JSON response ───────────────────────────────────────── */
    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_popular_recipes] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
