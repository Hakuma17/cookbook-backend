<?php
/**
 * get_cart_ingredients.php — รวมวัตถุดิบจากทุก recipe ในตะกร้า (Aggregate Shopping List)
 * =====================================================================================
 * METHOD: GET
 * PURPOSE:
 *   - รวมปริมาณวัตถุดิบทุกเมนูในตะกร้า แล้ว scale ตาม nServings ของผู้ใช้แต่ละเมนู
 *   - ตรวจแพ้อาหารแบบ "ขยายเป็นทั้งกลุ่ม" (newcatagory) → ถ้าแพ้กลุ่มหนึ่ง วัตถุดิบอื่นในกลุ่มนั้นถือว่าแพ้ด้วย
 * OUTPUT (success=true):
 *   {
 *     success: true,
 *     total_items: <int>,
 *     data: [
 *        { id, ingredient_id, name, quantity, unit, grams_actual|null,
 *          image_url, has_allergy, unit_conflict, group_code, group_name }
 *     ]
 *   }
 * LOGIC SUMMARY:
 *   1) ดึงรายการสูตรใน cart → คำนวณ factor (target/base) สำหรับแต่ละ recipe
 *   2) ดึงวัตถุดิบทั้งหมดของสูตรที่เกี่ยวข้อง
 *   3) ขยายชุดแพ้อาหาร: จาก ingredient ที่แพ้ → หา ingredient อื่นที่อยู่ใน newcatagory เดียวกัน
 *   4) รวมยอดตาม key = ingredient_id + unit (กันรวมผิดกรณีคนละหน่วย) พร้อมรวม grams_actual แยก
 *   5) ทำธง unit_conflict: ถ้าวัตถุดิบเดียวกันมีหลายหน่วย (เช่น 2 ถ้วย + 500 กรัม) → true
 *
 * PERFORMANCE:
 *   - จำนวนเมนูใน cart ปกติไม่มาก O(n)
 *   - ใช้ single query ingredients + group by
 *   - ขยายแพ้แบบกลุ่มหนึ่ง query เพิ่ม (JOIN self) → คอมเพล็กซ์ขึ้นเล็กน้อยแต่ควบคุมได้
 *
 * DIFFERENCE vs get_cart_items:
 *   - ไฟล์นั้นแยกตาม recipe และใช้ allergy แบบ ingredient id ตรง ๆ
 *   - ไฟล์นี้รวมและขยายแพ้เป็นกลุ่ม พร้อมตรวจ unit_conflict
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';     // bootstrap config
require_once __DIR__ . '/inc/functions.php';  // normalizeImageUrl, mapGroupFromNutritionId
require_once __DIR__ . '/inc/db.php';         // db wrappers

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // read-only endpoint
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeImageUrl และ mapGroupFromNutritionId ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $userId = requireLogin(); // ต้องล็อกอินเท่านั้น

    /* 1) ดึงเมนูใน cart + base/target servings เพื่อสร้าง factor สเกลปริมาณ */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings AS target, r.nServings AS base
        FROM cart c
        JOIN recipe r ON r.recipe_id = c.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) { // ไม่มีเมนูอะไร → ส่งกลับว่าง
        jsonOutput(['success' => true, 'data' => [], 'total_items' => 0]);
    }

    // คำนวณ factor ต่อ recipe_id (target/base)
    $factorByRecipe = [];
    foreach ($recipes as $rc) {
        $rid = (int)$rc['recipe_id'];
        $base = (float)$rc['base'];
        $target = (float)$rc['target'];
        $factorByRecipe[$rid] = ($base > 0) ? ($target / $base) : 1.0;
    }

    /* 2) ดึงวัตถุดิบทั้งหมดของ recipe ใน cart */
    $recipeIds = array_keys($factorByRecipe);
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    
    // ใช้ LEFT JOIN nutrition (ผ่าน ingredient→nutrition) + GROUP BY กัน duplication
    $ings = dbAll("
        SELECT
            ri.recipe_id,
            ri.ingredient_id,
            i.name,
            COALESCE(i.image_url,'') AS image_url,
            ri.quantity,
            ri.unit,
            ri.grams_actual,
            MIN(n.nutrition_id) AS nutrition_id
        FROM recipe_ingredient ri
        JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
        LEFT JOIN nutrition n ON n.ingredient_id = i.ingredient_id
        WHERE ri.recipe_id IN ($placeholders)
        GROUP BY ri.recipe_id, ri.ingredient_id, i.name, i.image_url, ri.unit, ri.quantity, ri.grams_actual
    ", $recipeIds);

    /* 2.5) ขยายแพ้อาหารแบบกลุ่ม: หา ingredient อื่นที่ newcatagory เดียวกับที่แพ้ (self JOIN) */
    $blockedRows = dbAll("
        SELECT DISTINCT i2.ingredient_id
        FROM allergyinfo a
        JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
        JOIN ingredients i2 ON TRIM(i2.newcatagory) = TRIM(ia.newcatagory)
        WHERE a.user_id = ?
          AND ia.newcatagory IS NOT NULL
          AND TRIM(ia.newcatagory) <> ''
    ", [$userId]);

    $blockedIds = array_map('intval', array_column($blockedRows, 'ingredient_id')); // list id ที่ห้ามใช้
    $blockedSet = array_fill_keys($blockedIds, true); // แปลงเป็น set lookup O(1)

    /* 3) รวมยอดตาม key (ingredient_id + unit) ป้องกันหน่วยต่างกันรวมมั่ว */
    $map = [];
    $unitTracker = [];

    foreach ($ings as $g) {
        $rid    = (int)$g['recipe_id'];
        $iid    = (int)$g['ingredient_id'];
        $name   = (string)$g['name'];
        $unit   = (string)($g['unit'] ?? '');
        $qtyRaw = (float)$g['quantity'];
        $gRaw   = isset($g['grams_actual']) ? (float)$g['grams_actual'] : null;

        $factor = $factorByRecipe[$rid] ?? 1.0;
    $qty    = $qtyRaw * $factor;                       // ปริมาณที่สเกลแล้ว
    $grams  = is_null($gRaw) ? null : $gRaw * $factor; // grams_actual เผื่อ null

        list($gcode, $gname) = mapGroupFromNutritionId($g['nutrition_id'] ?? '');
        $key = "{$iid}_{$unit}";

    if (!isset($map[$key])) { // สร้าง entry ใหม่
            $map[$key] = [
                'ingredient_id' => $iid,
                'id'              => $iid, // alias ให้ FE reuse ได้
                'name'            => $name,
                'quantity'        => $qty,
                'unit'            => $unit,
                'grams_actual'    => $grams,
                'image_url'       => normalizeImageUrl($g['image_url']), // เรียกใช้ฟังก์ชันกลาง
                'has_allergy'     => isset($blockedSet[$iid]),
                'group_code'      => $gcode,
                'group_name'      => $gname,
            ];
        } else { // มีอยู่แล้ว → รวมปริมาณ
            $map[$key]['quantity'] += $qty; // sum ตาม unit เดียวกัน
            if (!is_null($map[$key]['grams_actual']) || !is_null($grams)) { // รวม grams หากมีสักด้าน
                $map[$key]['grams_actual'] = ($map[$key]['grams_actual'] ?? 0) + ($grams ?? 0);
            }
        }

        $unitTracker[$iid][$unit] = true;
    }

    /* 4) เคลียร์ผลลัพธ์สุดท้าย: ใส่ unit_conflict, ปัดทศนิยม */
    $result = [];
    foreach ($map as $m) {
        $iid = (int)$m['ingredient_id'];
    $m['unit_conflict'] = isset($unitTracker[$iid]) && count($unitTracker[$iid]) > 1; // ถ้ามีหลายหน่วย
    $m['quantity']      = round((float)$m['quantity'], 2); // ปัด 2 ตำแหน่ง
        if (isset($m['grams_actual'])) {
            $m['grams_actual'] = round((float)$m['grams_actual'], 2); // ปัด grams
        }
        $result[] = $m;
    }

    jsonOutput([
        'success'     => true,
        'total_items' => count($result), // จำนวนวัตถุดิบ (key) หลังรวม
        'data'        => $result,        // รายการรวม
    ]); // END response

} catch (Throwable $e) {
    error_log('[cart_ingredients] ' . $e->getMessage() . ' on line ' . $e->getLine()); // Log ภายใน
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);                 // ไม่เปิดเผยรายละเอียด
}