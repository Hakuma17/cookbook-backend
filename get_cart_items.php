<?php
/**
 * get_cart_items.php — ดึงรายละเอียด "เมนูในตะกร้า" แต่ละรายการ + วัตถุดิบ (สเกลตาม nServings ที่ผู้ใช้เลือก)
 * =====================================================================================
 * METHOD: GET
 * RESPONSE (success=true):
 * {
 *   success: true,
 *   totalItems: <int>,
 *   data: [
 *     {
 *       recipe_id, name, prep_time|null, average_rating (float), review_count (int),
 *       nServings (float เลือกปัจจุบันใน cart), image_url, has_allergy(bool แบบ ingredient id ตรง),
 *       ingredients: [
 *          { ingredient_id, name, quantity (scaled), unit, grams_actual|null (scaled),
 *            image_url, has_allergy(bool), unit_conflict(false always here), group_code, group_name }
 *       ]
 *     }, ...
 *   ]
 * }
 *
 * SERVING SCALE FACTOR:
 *   factor = cart.nServings / recipe.nServings (ถ้า base = 0 → 1.0 ป้องกันหารศูนย์)
 *
 * ALLERGY LOGIC (ในไฟล์นี้):
 *   - ตรวจ ingredient_id ตรงกับ allergyinfo (ยังไม่ได้ขยายแบบกลุ่ม; กลุ่มจะมีใน get_cart_ingredients)
 *   - ฟิลด์ has_allergy ระดับ recipe: true ถ้า ingredients ตัวใดตัวหนึ่งอยู่ในชุดแพ้
 *
 * PERFORMANCE NOTES:
 *   - 1 query สำหรับ recipes, 1 query สำหรับ ingredients (IN (...)), 1 query allergy list → จำนวนแถวโดยรวมเล็ก
 *   - ใช้ GROUP BY กับ nutrition LEFT JOIN (ป้องกันซ้ำ)
 *
 * EXTENSIONS (TODO):
 *   - เพิ่มโหมดแพ้อาหารแบบกลุ่มเหมือน endpoints อื่น
 *   - เพิ่ม caching ต่อ user ถ้าต้องแสดงหน้าบ่อย (invalid เมื่อ cart เปลี่ยน)
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';    // โหลด environment + autoload
require_once __DIR__ . '/inc/functions.php'; // ฟังก์ชันกลาง (getBaseUrl, normalizeImageUrl, mapGroupFromNutritionId)
require_once __DIR__ . '/inc/db.php';        // wrapper PDO

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // จำกัดให้เป็น read-only
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeIngUrl และ mapGroupFromNutritionId ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $userId = requireLogin();                                   // ต้องล็อกอิน
    $baseRecipeUrl = rtrim(getBaseUrl(), '/') . '/uploads/recipes'; // base path ภาพสูตร

    /* 1) ดึงรายการเมนูใน cart พร้อม base servings ของ recipe (สำหรับคำนวณ factor) */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings cart_serv,
               r.name, r.image_path, r.prep_time,
               r.average_rating, r.nReviewer, r.nServings base_serv
        FROM cart c JOIN recipe r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) { // ไม่มีสินค้าในตะกร้า → ตอบกลับว่างเร็ว (early return)
        jsonOutput(['success' => true, 'totalItems' => 0, 'data' => []]);
        exit;
    }

    /* 2) ดึงวัตถุดิบทั้งหมดของเมนูใน cart (ใช้ IN + placeholders ป้องกัน SQL injection) */
    $ids = array_column($recipes, 'recipe_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    
    // ใช้ LEFT JOIN nutrition (ผ่าน nutrition_id ใน ingredients อาจต่าง schema) + GROUP BY ป้องกันแถวซ้ำ
    $ings = dbAll("
        SELECT
            ri.recipe_id,
            ri.ingredient_id,
            i.name,
            i.image_url,
            ri.quantity,
            ri.unit,
            ri.grams_actual,
            MIN(n.nutrition_id) AS nutrition_id
        FROM recipe_ingredient ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        LEFT JOIN nutrition n ON n.ingredient_id = i.ingredient_id
        WHERE ri.recipe_id IN ($ph)
        GROUP BY ri.recipe_id, ri.ingredient_id, i.name, i.image_url, ri.unit, ri.quantity, ri.grams_actual
    ", $ids);

    /* 3) สร้างดัชนี (map) recipe_id → [ingredients...] เพื่อลด O(N*M) loop ภายหลัง */
    $ingMap = [];
    foreach ($ings as $g) {
        $rid = (int)$g['recipe_id'];
        $ingMap[$rid][] = $g;
    }

    /* 4) ดึงรายการวัตถุดิบที่ผู้ใช้แพ้ (แบบตรง ๆ) แล้วทำ set lookup O(1) */
    $stmt = pdo()->prepare('SELECT ingredient_id FROM allergyinfo WHERE user_id = ?');
    $stmt->execute([$userId]);
    // ★ แก้ไข: ใช้ fetchAll(PDO::FETCH_COLUMN) เพื่อความกระชับ
    $allergyIds = $stmt->fetchAll(PDO::FETCH_COLUMN);  // ได้ array ของ ingredient_id (string/int)
    $allergySet = array_fill_keys($allergyIds, true);   // เปลี่ยนเป็น associative set


    /* 5) ประกอบผลลัพธ์: scale ปริมาณตาม factor, คำนวณ has_allergy */
    $data = [];
    foreach ($recipes as $r) {
        $rid       = (int)$r['recipe_id'];
        $cartServe = (float)$r['cart_serv'];
        $baseServe = (float)$r['base_serv'];
    $factor    = $baseServe > 0 ? $cartServe / $baseServe : 1.0; // ป้องกันหาร 0

        $ingredientList = [];
        $hasAllergy = false;
    foreach ($ingMap[$rid] ?? [] as $g) { // loop วัตถุดิบของสูตรนี้ (ถ้าไม่มี → ข้าม)
            $iid   = (int)$g['ingredient_id'];
            $qty   = round(((float)($g['quantity'] ?? 0)) * $factor, 2); // สเกลตาม factor
            $gBase = isset($g['grams_actual']) ? (float)$g['grams_actual'] : null;
            $gOut  = is_null($gBase) ? null : round($gBase * $factor, 2);
            list($gcode, $gname) = mapGroupFromNutritionId($g['nutrition_id'] ?? '');

            if (isset($allergySet[$iid])) { // หากส่วนผสมนี้อยู่ในชุดแพ้
                $hasAllergy = true;          // ติดธงระดับ recipe
            }

            $ingredientList[] = [ // push ingredient (scaled)
                'ingredient_id' => $iid,
                'name'            => (string)$g['name'],
                'quantity'        => $qty,
                'unit'            => (string)$g['unit'],
                'grams_actual'    => $gOut,
                'image_url'       => normalizeImageUrl($g['image_url']), // เรียกใช้ฟังก์ชันกลาง
                'has_allergy'     => isset($allergySet[$iid]),
                'unit_conflict'   => false,
                'group_code'      => $gcode,
                'group_name'      => $gname,
            ];
        }

    $imgFile = $r['image_path'] ?: 'default_recipe.png'; // fallback ชื่อภาพ
    $data[] = [ // push recipe item
            'recipe_id'      => $rid,
            'name'           => (string)$r['name'],
            'prep_time'      => $r['prep_time'] ? (int)$r['prep_time'] : null,
            'average_rating' => (float)$r['average_rating'],
            'review_count'   => (int)$r['nReviewer'],
            'nServings'      => $cartServe,
            'image_url'      => $baseRecipeUrl . '/' . basename($imgFile),
            'has_allergy'    => $hasAllergy,
            'ingredients'    => $ingredientList,
        ];
    }

    jsonOutput(['success' => true, 'totalItems' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    error_log('[cart_items] ' . $e->getMessage() . ' on line ' . $e->getLine());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}