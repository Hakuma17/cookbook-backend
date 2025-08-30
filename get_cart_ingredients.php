<?php
// get_cart_ingredients.php — รวมวัตถุดิบจากทุกเมนูในตะกร้า (+ ขยายรายการแพ้อาหารเป็นทั้งกลุ่ม)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php'; // ★ ใช้ฟังก์ชันกลางจากที่นี่
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeImageUrl และ mapGroupFromNutritionId ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $userId = requireLogin();

    /* 1) เมนูในตะกร้า + base/target servings เพื่อคำนวณ factor */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings AS target, r.nServings AS base
        FROM cart c
        JOIN recipe r ON r.recipe_id = c.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) {
        // ออกจากการทำงานได้เลยถ้าไม่มีเมนูในตะกร้า
        jsonOutput(['success' => true, 'data' => [], 'total_items' => 0]);
    }

    // คำนวณ factor ต่อ recipe_id
    $factorByRecipe = [];
    foreach ($recipes as $rc) {
        $rid = (int)$rc['recipe_id'];
        $base = (float)$rc['base'];
        $target = (float)$rc['target'];
        $factorByRecipe[$rid] = ($base > 0) ? ($target / $base) : 1.0;
    }

    /* 2) ดึงวัตถุดิบของเมนูทั้งหมด */
    $recipeIds = array_keys($factorByRecipe);
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    
    // ★ แก้ไข: เปลี่ยนมาใช้ LEFT JOIN + GROUP BY เพื่อประสิทธิภาพที่ดีขึ้น
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

    /* 2.5) ขยายแพ้อาหารเป็นทั้งกลุ่ม (อิง newcatagory) */
    $blockedRows = dbAll("
        SELECT DISTINCT i2.ingredient_id
        FROM allergyinfo a
        JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
        JOIN ingredients i2 ON TRIM(i2.newcatagory) = TRIM(ia.newcatagory)
        WHERE a.user_id = ?
          AND ia.newcatagory IS NOT NULL
          AND TRIM(ia.newcatagory) <> ''
    ", [$userId]);

    $blockedIds = array_map('intval', array_column($blockedRows, 'ingredient_id'));
    $blockedSet = array_fill_keys($blockedIds, true);

    /* 3) รวมยอดตาม (ingredient_id + unit) */
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
        $qty    = $qtyRaw * $factor;
        $grams  = is_null($gRaw) ? null : $gRaw * $factor;

        list($gcode, $gname) = mapGroupFromNutritionId($g['nutrition_id'] ?? '');
        $key = "{$iid}_{$unit}";

        if (!isset($map[$key])) {
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
        } else {
            $map[$key]['quantity'] += $qty;

            // ★ แก้ไข: รวม grams_actual แบบกระชับและปลอดภัย
            if (!is_null($map[$key]['grams_actual']) || !is_null($grams)) {
                $map[$key]['grams_actual'] = ($map[$key]['grams_actual'] ?? 0) + ($grams ?? 0);
            }
        }

        $unitTracker[$iid][$unit] = true;
    }

    /* 4) ธง unit_conflict + ปัดทศนิยม */
    $result = [];
    foreach ($map as $m) {
        $iid = (int)$m['ingredient_id'];
        $m['unit_conflict'] = isset($unitTracker[$iid]) && count($unitTracker[$iid]) > 1;
        $m['quantity']      = round((float)$m['quantity'], 2);
        if (isset($m['grams_actual'])) {
            $m['grams_actual'] = round((float)$m['grams_actual'], 2);
        }
        $result[] = $m;
    }

    jsonOutput([
        'success'     => true,
        'total_items' => count($result),
        'data'        => $result,
    ]);

} catch (Throwable $e) {
    error_log('[cart_ingredients] ' . $e->getMessage() . ' on line ' . $e->getLine());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}