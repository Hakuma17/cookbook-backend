<?php
// get_cart_items.php — รายการเมนูในตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $userId = requireLogin();
    $baseRecipeUrl = getBaseUrl() . '/uploads/recipes';
    $baseIngUrl    = getBaseUrl() . '/uploads/ingredients';

    /* 1) เมนู + เสิร์ฟ */
    $recipes = dbAll("
        SELECT c.recipe_id, c.nServings cart_serv,
               r.name, r.image_path, r.prep_time,
               r.average_rating, r.nReviewer, r.nServings base_serv
        FROM cart c JOIN recipe r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = ?
    ", [$userId]);

    if (!$recipes) {
        jsonOutput(['success' => true, 'totalItems' => 0, 'data' => []]);
    }

    /* 2) วัตถุดิบของเมนูทั้งหมด */
    $ids = implode(',', array_column($recipes, 'recipe_id'));
    $ings = dbAll("
        SELECT ri.recipe_id, ri.ingredient_id, i.name, i.image_url,
               ri.quantity, ri.unit
        FROM recipe_ingredient ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        WHERE ri.recipe_id IN ($ids)
    ");

    /* 3) สร้าง map วัตถุดิบต่อเมนู */
    $ingMap = [];
    foreach ($ings as $g) {
        $ingMap[$g['recipe_id']][] = $g;
    }

    /* 4) ตรวจแพ้อาหาร (ครั้งเดียว) */
    $allergyIds = dbAll('SELECT ingredient_id FROM allergyinfo WHERE user_id = ?', [$userId]);
    $allergyIds = array_column($allergyIds, 'ingredient_id');

    /* 5) ประกอบผลลัพธ์ */
    $data = [];
    foreach ($recipes as $r) {
        $rid        = (int)$r['recipe_id'];
        $cartServe  = (float)$r['cart_serv'];
        $baseServe  = (float)$r['base_serv'];
        $factor     = $baseServe > 0 ? $cartServe / $baseServe : 1;

        $ingredientList = [];
        foreach ($ingMap[$rid] ?? [] as $g) {
            $ingredientList[] = [
                'ingredient_id' => (int)$g['ingredient_id'],
                'name'          => $g['name'],
                'quantity'      => round(($g['quantity'] ?? 0) * $factor, 2),
                'unit'          => $g['unit'],
                'image_url'     => $g['image_url']
                    ? "{$baseIngUrl}/" . basename($g['image_url'])
                    : "{$baseIngUrl}/default_ingredients.png",
                'unit_conflict' => false
            ];
        }

        $imgFile = $r['image_path'] ?: 'default_recipe.png';
        $hasAllergy = false;
        if ($allergyIds && $ingredientList) {
            foreach ($ingredientList as $it) {
                if (in_array($it['ingredient_id'], $allergyIds)) {
                    $hasAllergy = true;
                    break;
                }
            }
        }

        $data[] = [
            'recipe_id'      => $rid,
            'name'           => $r['name'],
            'prep_time'      => $r['prep_time'] ? (int)$r['prep_time'] : null,
            'average_rating' => (float)$r['average_rating'],
            'review_count'   => (int)$r['nReviewer'],
            'nServings'      => $cartServe,
            'image_url'      => "{$baseRecipeUrl}/" . basename($imgFile),
            'has_allergy'    => $hasAllergy,
            'ingredients'    => $ingredientList
        ];
    }

    jsonOutput(['success' => true, 'totalItems' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    error_log('[cart_items] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
