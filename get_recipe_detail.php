<?php
/**
 * get_recipe_detail.php — รายละเอียดสูตรเต็ม (Full Recipe Detail)
 * =====================================================================================
 * INPUT:
 *   - GET id=<recipe_id:int>
 * OUTPUT (success=true):
 *   {
 *     success: true,
 *     data: {
 *       recipe_id, name, image_urls[string[]], prep_time, nServings,
 *       average_rating, review_count, created_at, source_reference,
 *       ingredients: [{ ingredient_id, name, image_url, quantity, unit, grams_actual, descrip }...],
 *       steps: [{ step_number, description }...],
 *       nutrition: { calories, protein, fat, carbs },
 *       is_favorited (bool), user_rating (int|null), current_servings (int), has_allergy (bool),
 *       categories: [category_name,...],
 *       comments_url: string   // 🔁 ดึงคอมเมนต์จาก endpoint get_comments.php (เลิกคืน comments ตรงนี้)
 *     }
 *   }
 *   กรณีไม่พบ → 404 { success:false, message }
 *
 * ALLERGY CHECK:
 *   - ใช้ EXISTS เปรียบเทียบ newcatagory (กลุ่ม) ระหว่างส่วนผสมในสูตร กับรายการแพ้ของผู้ใช้
 *
 * NUTRITION AGGREGATION:
 *   - สูตรรวม: sum(nutrient_per100g * grams_actual / 100)
 *
 * PERFORMANCE NOTES:
 *   - ลดงานซ้ำ: ไม่ JOIN review ที่นี่ ให้ FE ไปเรียก get_comments.php เอง
 *
 * SECURITY:
 *   - READ only; ใช้ prepared statements
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$rid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($rid <= 0) {
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    /** 1) ข้อมูลหลัก ********************************************************/
    $row = dbOne("
        SELECT recipe_id, name, image_path, prep_time, nServings,
               average_rating,
               (SELECT COUNT(*) FROM review WHERE recipe_id = ?) AS review_count,
               created_at, source_reference
        FROM recipe
        WHERE recipe_id = ?
        LIMIT 1
    ", [$rid, $rid]);

    if (!$row) {
        jsonOutput(['success' => false, 'message' => 'ไม่พบสูตรอาหาร'], 404);
    }

    /* base image */
    $baseRec = rtrim(getBaseUrl(), '/') . '/uploads/recipes';
    $file    = $row['image_path'] ?: 'default_recipe.png';
    $row['image_urls'] = ["{$baseRec}/" . basename($file)];

    /** 2) วัตถุดิบ ***********************************************************/
    $row['ingredients'] = dbAll("
        SELECT ri.ingredient_id, i.name, i.image_url, ri.quantity, ri.unit,
               ri.grams_actual, ri.descrip
        FROM recipe_ingredient ri
        JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
        WHERE ri.recipe_id = ?
        ORDER BY ri.id
    ", [$rid]) ?: [];

    /** 3) ขั้นตอน ************************************************************/
    $row['steps'] = dbAll("
        SELECT step_number, description
        FROM step
        WHERE recipe_id = ?
        ORDER BY step_number
    ", [$rid]) ?: [];

    /** 4) โภชนาการ **********************************************************/
    $nut = dbOne("
        SELECT SUM(n.energy_kcal * ri.grams_actual/100)      AS cal,
               SUM(n.protein_g    * ri.grams_actual/100)      AS pro,
               SUM(n.fat_g        * ri.grams_actual/100)      AS fat,
               SUM(n.carbohydrate_g * ri.grams_actual/100)    AS carb
        FROM recipe_ingredient ri
        JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
        JOIN nutrition   n ON n.nutrition_id = i.nutrition_id
        WHERE ri.recipe_id = ?
    ", [$rid]) ?: [];

    $row['nutrition'] = [
        'calories' => round((float)($nut['cal'] ?? 0), 1),
        'protein'  => round((float)($nut['pro'] ?? 0), 1),
        'fat'      => round((float)($nut['fat'] ?? 0), 1),
        'carbs'    => round((float)($nut['carb'] ?? 0), 1),
    ];

    /** 5) ข้อมูลเฉพาะผู้ใช้ **************************************************/
    $uid = getLoggedInUserId();
    $row += [
        'is_favorited'     => false,
        'user_rating'      => null,
        'current_servings' => (int)($row['nServings'] ?? 1),
        'has_allergy'      => false
    ];

    if ($uid) {
        $row['is_favorited'] = dbVal("
            SELECT COUNT(*) FROM favorites WHERE recipe_id = ? AND user_id = ?
        ", [$rid, $uid]) > 0;

        $row['user_rating'] = dbVal("
            SELECT rating FROM review WHERE recipe_id = ? AND user_id = ? LIMIT 1
        ", [$rid, $uid]) ?: null;

        $sv = dbVal("
            SELECT nServings FROM cart WHERE recipe_id = ? AND user_id = ? LIMIT 1
        ", [$rid, $uid]);
        if (is_numeric($sv)) {
            $row['current_servings'] = (int)$sv;
        }

        // [NEW] เช็กแพ้อาหารแบบกลุ่ม (newcatagory)
        $row['has_allergy'] = dbVal("
            SELECT COUNT(*)
            FROM recipe_ingredient ri
            JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
            WHERE ri.recipe_id = ?
              AND EXISTS (
                SELECT 1
                FROM allergyinfo a
                JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                WHERE a.user_id = ?
                  AND TRIM(ia.newcatagory) = TRIM(i.newcatagory)
              )
        ", [$rid, $uid]) > 0;
    }

    /** 6) ความคิดเห็น *********************************************************/
    // 🔁 เลิกคิวรีรีวิวตรงนี้เพื่อลดการซ้ำซ้อนกับ get_comments.php
    // ให้ FE เรียกคอมเมนต์ผ่าน endpoint กลางแทน:
    $row['comments_url'] = rtrim(getBaseUrl(), '/') . '/get_comments.php?id=' . urlencode((string)$rid);
    // หมายเหตุ: ถ้าต้องการ “คงรูปแบบเดิม” สามารถให้ FE เรียก comments_url แล้ว merge data เอง

    /** 7) หมวดหมู่ ***********************************************************/
    $row['categories'] = dbAll("
        SELECT c.category_name
        FROM category_recipe cr
        JOIN category c ON c.category_id = cr.category_id
        WHERE cr.recipe_id = ?
    ", [$rid], PDO::FETCH_COLUMN) ?: [];

    /** 8) ส่งกลับ ************************************************************/
    jsonOutput(['success' => true, 'data' => $row]);

} catch (Throwable $e) {
    error_log('[recipe_detail] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
