<?php
// get_cart_ingredients.php
// คืนรายการวัตถุดิบรวมจากทุกสูตรในตะกร้า (รวมจำนวน-เช็ค unit conflict)

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ตรวจสอบล็อกอินและดึง user_id
    $userId = requireLogin();

    // 2) ดึงข้อมูลสูตร + จำนวนเสิร์ฟ (cart vs base)
    $stmt = $pdo->prepare("
        SELECT
            c.recipe_id,
            c.nServings      AS target_servings,
            r.nServings      AS base_servings
        FROM cart AS c
        JOIN recipe AS r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ถ้าไม่มีสูตรใน cart
    if (empty($recipes)) {
        jsonOutput([
            'success'     => true,
            'total_items' => 0,
            'data'        => []
        ]);
        exit;
    }

    // 3) ดึงวัตถุดิบของแต่ละสูตร แล้วรวมตาม ingredient_id + unit
    $map = [];        // key = "{ingredient_id}_{unit}"
    $unitTracker = []; // ingredient_id => [unit1,unit2,...]

    foreach ($recipes as $r) {
        $factor = $r['base_servings'] > 0
            ? floatval($r['target_servings']) / floatval($r['base_servings'])
            : 1.0;

        $stmt2 = $pdo->prepare("
            SELECT
                ri.ingredient_id,
                i.name,
                i.image_url,
                ri.quantity,
                ri.unit
            FROM recipe_ingredient AS ri
            JOIN ingredients AS i ON ri.ingredient_id = i.ingredient_id
            WHERE ri.recipe_id = :rid
        ");
        $stmt2->execute(['rid' => $r['recipe_id']]);
        $ings = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ings as $ing) {
            $key = $ing['ingredient_id'] . '_' . $ing['unit'];
            $qty = floatval($ing['quantity']) * $factor;

            if (!isset($map[$key])) {
                $map[$key] = [
                    'ingredient_id' => (int)$ing['ingredient_id'],
                    'name'          => $ing['name'],
                    'quantity'      => $qty,
                    'unit'          => $ing['unit'],
                    'image_url'     => $ing['image_url'],
                ];
            } else {
                $map[$key]['quantity'] += $qty;
            }
            $unitTracker[$ing['ingredient_id']][$ing['unit']] = true;
        }
    }

    // 4) ตรวจ unit conflict แต่ละรายการ
    foreach ($map as &$entry) {
        $iid = $entry['ingredient_id'];
        $entry['unit_conflict'] =
            isset($unitTracker[$iid]) && count($unitTracker[$iid]) > 1;
    }
    unset($entry);

    // 5) ตอบกลับ JSON
    jsonOutput([
        'success'     => true,
        'total_items' => count($map),
        'data'        => array_values($map),
    ]);
} catch (Exception $e) {
    jsonOutput([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}
