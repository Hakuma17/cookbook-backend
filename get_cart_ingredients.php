<?php
// get_cart_ingredients.php — รวมวัตถุดิบจากทุกเมนูในตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $userId = requireLogin();

    /* 1) ดึงเมนู + สัดส่วนเสิร์ฟ */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings AS target, r.nServings AS base
        FROM cart c JOIN recipe r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) {
        jsonOutput(['success' => true, 'data' => [], 'total_items' => 0]);
    }

    /* 2) ดึงวัตถุดิบของเมนูทั้งหมดในตะกร้า */
    $ids = implode(',', array_column($recipes, 'recipe_id'));
    $ings = dbAll("
        SELECT ri.recipe_id, ri.ingredient_id, i.name, i.image_url,
               ri.quantity, ri.unit
        FROM recipe_ingredient ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        WHERE ri.recipe_id IN ($ids)
    ");

    /* 3) รวมยอดตาม ingredient_id+unit */
    $baseUrl = getBaseUrl() . '/uploads/ingredients';
    $map = [];        // "id_unit" => item
    $unitTracker = [];  // id => set(unit)

    foreach ($ings as $g) {
        $factor = 1.0;
        foreach ($recipes as $rc) {
            if ($rc['recipe_id'] == $g['recipe_id'] && $rc['base'] > 0) {
                $factor = $rc['target'] / $rc['base'];
                break;
            }
        }

        $id = (int)$g['ingredient_id'];
        $unit = $g['unit'] ?? '';
        $key = "{$id}_{$unit}";
        $qty = (float)$g['quantity'] * $factor;

        if (!isset($map[$key])) {
            $map[$key] = [
                'ingredient_id' => $id,
                'name'          => $g['name'],
                'quantity'      => $qty,
                'unit'          => $unit,
                'image_url'     => $g['image_url']
                    ? "{$baseUrl}/" . basename($g['image_url'])
                    : "{$baseUrl}/default_ingredients.png",
            ];
        } else {
            $map[$key]['quantity'] += $qty;
        }
        $unitTracker[$id][$unit] = true;
    }

    /* 4) ติดธง unit_conflict */
    foreach ($map as &$m) {
        $iid = $m['ingredient_id'];
        $m['unit_conflict'] = isset($unitTracker[$iid]) && count($unitTracker[$iid]) > 1;
        $m['quantity'] = round($m['quantity'], 2);
    }

    jsonOutput([
        'success'      => true,
        'total_items'  => count($map),
        'data'         => array_values($map)
    ]);

} catch (Throwable $e) {
    error_log('[cart_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
