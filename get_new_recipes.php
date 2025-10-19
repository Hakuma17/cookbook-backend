<?php
/**
 * get_new_recipes.php — ดึง “สูตรมาใหม่” (ล่าสุดตาม created_at) จำกัด 10 รายการ
 * =====================================================================================
 * RESPONSE STRUCTURE (success=true):
 *   {
 *     success: true,
 *     data: [
 *       {
 *         recipe_id, name, prep_time|null,
 *         favorite_count, average_rating, review_count,
 *         short_ingredients (string ย่อ), ingredient_ids[int...],
 *         image_url, has_allergy (แพ้แบบเทียบกลุ่ม newcatagory),
 *         allergy_groups[string...],   // รายชื่อกลุ่มที่ชน (Trim แล้ว ไม่ซ้ำ)
 *         allergy_names[string...]     // Display name ของวัตถุดิบที่อยู่ในกลุ่มที่ผู้ใช้แพ้
 *       }, ... (สูงสุด 10)
 *     ]
 *   }
 * หมายเหตุ: ถ้าไม่ล็อกอิน → has_allergy = false, allergy_groups / allergy_names = []
 *
 * SECURITY / VALIDATION:
 *   - อ่านอย่างเดียว (GET) ป้องกันด้วย method check
 *   - ใช้ prepared statements (dbAll) ปลอด SQL injection
 *
 * PERFORMANCE & INDEX HINT:
 *   - ใช้ subquery COUNT / AVG ต่อ recipe: ถ้าปริมาณมากควรพิจารณา denormalize เก็บ favorite_count / avg_rating คอลัมน์ในตาราง recipe
 *   - GROUP_CONCAT อาจตัดหาก ingredient เยอะมาก (ค่าเริ่มต้น group_concat_max_len ~1024/1024* bytes) → ถ้าข้อมูลยาวควร SET SESSION group_concat_max_len เพิ่ม (ยังไม่จำเป็น immediate)
 *   - เพิ่มดัชนี: recipe(created_at), favorites(recipe_id), review(recipe_id), recipe_ingredient(recipe_id)
 *
 * ALLERGY LOGIC:
 *   - has_allergy: EXISTS วัตถุดิบในสูตรใด ๆ ที่ newcatagory ตรงกับรายการแพ้ของผู้ใช้ (เปรียบเทียบแบบ “กลุ่ม”) ลดปัญหา duplicate ingredient object
 *   - allergy_groups: ดึงรายการกลุ่มที่ชนเพื่อให้ FE ขึ้น chip สรุป
 *   - allergy_names: รวบรวม display_name ของวัตถุดิบแพ้ในกลุ่มที่เกี่ยวข้องเพื่อโชว์ชื่อมนุษย์อ่านง่าย
 *
 * COMPATIBILITY:
 *   - image_url: ใช้ default_recipe.png ให้ตรงกับ endpoint อื่น (ถ้าระบบจริงใช้ .jpg ทั้งหมด อาจเพิ่มไฟล์ .png เช่นเดียวกันเพื่อไม่สับสน)
 *
 * TODO (Optional):
 *   - เพิ่ม pagination (?page / ?limit) หากต้องการโหลดเพิ่มทีหลัง
 *   - Cache ชั้นบาง (เช่น Redis) สำหรับ guest เพื่อลด repeated aggregation
 * =====================================================================================
 */

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
    ORDER BY  r.created_at DESC, r.recipe_id DESC
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
