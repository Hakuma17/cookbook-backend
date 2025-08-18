<?php
// get_cart_ingredients.php — รวมวัตถุดิบจากทุกเมนูในตะกร้า (+ ขยายรายการแพ้อาหารเป็นทั้งกลุ่ม)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/**
 * ทำ URL รูปภาพให้เป็น absolute + fallback ถ้าไฟล์ไม่มีจริง
 * - ปล่อยผ่านกรณีเป็น http/https
 * - หากชื่อขึ้นต้น "ingredient_" จะลองสลับเป็น "ingredients_" ให้อัตโนมัติ
 * - สำหรับไฟล์นี้ default ใช้ "default_ingredients.png"
 */
function normalizeImageUrl(?string $raw, string $defaultFile = 'default_ingredients.png'): string {
    $baseUrl  = rtrim(getBaseUrl(), '/');
    $baseWeb  = $baseUrl . '/uploads/ingredients';
    $basePath = __DIR__ . '/uploads/ingredients';

    $raw = trim((string)$raw);
    if ($raw === '') return $baseWeb . '/' . $defaultFile;

    if (preg_match('~^https?://~i', $raw)) return $raw;

    $filename = basename(str_replace('\\', '/', $raw));
    $abs = $basePath . '/' . $filename;
    if (is_file($abs)) return $baseWeb . '/' . $filename;

    if (strpos($filename, 'ingredient_') === 0) {
        $alt = 'ingredients_' . substr($filename, strlen('ingredient_'));
        if (is_file($basePath . '/' . $alt)) return $baseWeb . '/' . $alt;
    }
    return $baseWeb . '/' . $defaultFile;
}

try {
    $userId = requireLogin();

    /* 1) ดึงเมนู + สัดส่วนเสิร์ฟ (base vs target) */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings AS target, r.nServings AS base
        FROM cart c
        JOIN recipe r ON r.recipe_id = c.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) {
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

    /* 2) ดึงวัตถุดิบทั้งหมดของเมนูในตะกร้า — ใช้ IN แบบ parameterized */
    $recipeIds = array_keys($factorByRecipe);
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    $ings = dbAll("
        SELECT ri.recipe_id, ri.ingredient_id, i.name, COALESCE(i.image_url,'') AS image_url,
               ri.quantity, ri.unit
        FROM recipe_ingredient ri
        JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
        WHERE ri.recipe_id IN ($placeholders)
    ", $recipeIds);

    /* 2.5) ดึงรายการแพ้อาหารของผู้ใช้ (ขยายเป็นทั้งกลุ่มด้วย newcatagory) */
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
    $blockedSet = array_fill_keys($blockedIds, true); // for O(1) lookup

    /* 3) รวมยอดตาม (ingredient_id + unit) */
    $map = [];          // "id_unit" => item
    $unitTracker = [];  // id => set(unit)

    foreach ($ings as $g) {
        $rid    = (int)$g['recipe_id'];
        $iid    = (int)$g['ingredient_id'];
        $name   = $g['name'];
        $unit   = (string)($g['unit'] ?? '');
        $qtyRaw = (float)$g['quantity'];
        $factor = $factorByRecipe[$rid] ?? 1.0;
        $qty    = $qtyRaw * $factor;

        $key = "{$iid}_{$unit}";

        if (!isset($map[$key])) {
            $map[$key] = [
                'ingredient_id' => $iid,
                'id'            => $iid,            // alias ให้ FE reuse ได้
                'name'          => $name,
                'quantity'      => $qty,
                'unit'          => $unit,
                'image_url'     => normalizeImageUrl($g['image_url'], 'default_ingredients.png'),
                'has_allergy'   => isset($blockedSet[$iid]), // ธงแพ้อาหาร (แบบกลุ่มแล้ว)
            ];
        } else {
            $map[$key]['quantity'] += $qty;
        }

        $unitTracker[$iid][$unit] = true;
    }

    /* 4) ติดธง unit_conflict + ปัดทศนิยม */
    foreach ($map as &$m) {
        $iid = (int)$m['ingredient_id'];
        $m['unit_conflict'] = isset($unitTracker[$iid]) && count($unitTracker[$iid]) > 1;
        $m['quantity'] = round((float)$m['quantity'], 2);
    }
    unset($m);

    jsonOutput([
        'success'      => true,
        'total_items'  => count($map),             // นับตาม key (id+unit)
        'data'         => array_values($map)
    ]);

} catch (Throwable $e) {
    error_log('[cart_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
