<?php
// get_ingredients.php — รายชื่อวัตถุดิบทั้งหมด (+ โหมด grouped=1 คืน “กลุ่มวัตถุดิบ” พร้อมรูปตัวแทน + recipe_count)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // 🔹 helper PDO wrapper

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    // ★★★ NEW: โหมดกลุ่ม — ถ้าเรียกด้วย ?grouped=1 จะคืนรายการ “กลุ่มวัตถุดิบ”
    $grouped = (isset($_GET['grouped']) && $_GET['grouped'] === '1');

    if ($grouped) {
        /*
         * โครงสร้างผลลัพธ์ (ตัวอย่าง):
         * {
         *   "success": true,
         *   "groups": [
         *     {
         *       "group_name": "กุ้ง",
         *       "representative_ingredient_id": 12,
         *       "representative_name": "กุ้งแห้ง",
         *       "image_url": "https://.../uploads/ingredients/shrimp_dry.png",
         *       "item_count": 5,            // จำนวนวัตถุดิบในกลุ่ม
         *       "recipe_count": 23,         // ★ จำนวนสูตรที่มีกลุ่มนี้
         *       "catagorynew": "กุ้ง"
         *     }
         *   ]
         * }
         */
        $rows = dbAll("
            SELECT 
                g.group_name,
                rep.ingredient_id                  AS representative_ingredient_id,
                rep.name                           AS representative_name,
                COALESCE(rep.image_url, '')        AS image_url,
                g.ingredient_count                 AS item_count,      -- จำนวนวัตถุดิบในกลุ่ม (info)
                COALESCE(rc.recipe_count, 0)       AS recipe_count,    -- ★ จำนวนสูตรจริงของกลุ่ม
                g.group_name                       AS catagorynew
            FROM (
                SELECT TRIM(newcatagory) AS group_name,
                       COUNT(*)          AS ingredient_count,
                       MIN(ingredient_id) AS rep_id
                FROM ingredients
                WHERE newcatagory IS NOT NULL AND TRIM(newcatagory) <> ''
                GROUP BY TRIM(newcatagory)
            ) g
            JOIN ingredients rep ON rep.ingredient_id = g.rep_id
            LEFT JOIN (
                SELECT TRIM(i.newcatagory) AS group_name,
                       COUNT(DISTINCT ri.recipe_id) AS recipe_count
                FROM ingredients i
                JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
                WHERE i.newcatagory IS NOT NULL AND TRIM(i.newcatagory) <> ''
                GROUP BY TRIM(i.newcatagory)
            ) rc ON rc.group_name = g.group_name
            ORDER BY g.group_name
        ");

        // ★ ทำ URL เต็ม + fallback รูป default_group.png (โฟลเดอร์ /uploads/ingredients)
        $baseIng = rtrim(getBaseUrl(), '/').'/uploads/ingredients';
        foreach ($rows as &$r) {
            $r['image_url'] = !empty($r['image_url'])
                ? $baseIng . '/' . basename($r['image_url'])
                : $baseIng . '/default_group.png';
        }
        unset($r);

        jsonOutput(['success' => true, 'groups' => $rows]);
        exit;
    }

    // ───────────────────────────────────────────────────────────────
    // โหมดเดิม: คืนวัตถุดิบ “รายตัว”
    $rows = dbAll("
        SELECT
            ingredient_id           AS id,
            name,
            COALESCE(image_url, '') AS image_url,
            category
        FROM ingredients
        ORDER BY name ASC
    ");
    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
