<?php
// get_cart_items.php — รายการเมนูในตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php'; // ★ ใช้ฟังก์ชันกลางจากที่นี่
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeIngUrl และ mapGroupFromNutritionId ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $userId = requireLogin();
    $baseRecipeUrl = rtrim(getBaseUrl(), '/') . '/uploads/recipes';

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
        exit;
    }

    /* 2) วัตถุดิบของเมนูทั้งหมด (parameterized IN) */
    $ids = array_column($recipes, 'recipe_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    
    // ★ แก้ไข: เปลี่ยนมาใช้ LEFT JOIN + GROUP BY เพื่อประสิทธิภาพที่ดีขึ้น
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

    /* 3) สร้าง map วัตถุดิบต่อเมนู */
    $ingMap = [];
    foreach ($ings as $g) {
        $rid = (int)$g['recipe_id'];
        $ingMap[$rid][] = $g;
    }

    /* 4) ตรวจแพ้อาหาร (ครั้งเดียว) */
    $stmt = pdo()->prepare('SELECT ingredient_id FROM allergyinfo WHERE user_id = ?');
    $stmt->execute([$userId]);
    // ★ แก้ไข: ใช้ fetchAll(PDO::FETCH_COLUMN) เพื่อความกระชับ
    $allergyIds = $stmt->fetchAll(PDO::FETCH_COLUMN); 
    $allergySet = array_fill_keys($allergyIds, true);


    /* 5) ประกอบผลลัพธ์ */
    $data = [];
    foreach ($recipes as $r) {
        $rid       = (int)$r['recipe_id'];
        $cartServe = (float)$r['cart_serv'];
        $baseServe = (float)$r['base_serv'];
        $factor    = $baseServe > 0 ? $cartServe / $baseServe : 1.0;

        $ingredientList = [];
        $hasAllergy = false;
        foreach ($ingMap[$rid] ?? [] as $g) {
            $iid   = (int)$g['ingredient_id'];
            $qty   = round(((float)($g['quantity'] ?? 0)) * $factor, 2);
            $gBase = isset($g['grams_actual']) ? (float)$g['grams_actual'] : null;
            $gOut  = is_null($gBase) ? null : round($gBase * $factor, 2);
            list($gcode, $gname) = mapGroupFromNutritionId($g['nutrition_id'] ?? '');

            if (isset($allergySet[$iid])) {
                $hasAllergy = true;
            }

            $ingredientList[] = [
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

        $imgFile = $r['image_path'] ?: 'default_recipe.png';
        $data[] = [
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