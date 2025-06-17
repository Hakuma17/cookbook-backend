<?php
// get_cart_items.php
// คืนรายการเมนูในตะกร้าของผู้ใช้ (Cart Items) พร้อมยอดรวมจำนวนรายการและวัตถุดิบ

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ตรวจสอบล็อกอินและดึง user_id
    // สำหรับทดสอบ ให้คอมเมนต์บรรทัด requireLogin() และกำหนด userId ตรงนี้ก่อน
     $userId = requireLogin();
    //$userId = 3;  // สมมติ user_id = 3 (ปรับตามข้อมูลในตาราง cart)

    // 2) ดึงรายการเมนูในตะกร้า พร้อมข้อมูลจาก recipe
    $sqlRecipes = "
        SELECT
            c.recipe_id,
            c.nServings AS cart_servings,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            r.nReviewer AS review_count,
            r.nServings AS recipe_servings
        FROM cart AS c
        JOIN recipe AS r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = :user_id
    ";
    $stmt = $pdo->prepare($sqlRecipes);
    $stmt->execute(['user_id' => $userId]);
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3) ดึงวัตถุดิบของทุกสูตรที่อยู่ในตะกร้า
    // ใช้ recipe_ingredient แทน cart_ingredient และดึงคอลัมน์ image_url จากตาราง ingredients
    $sqlIng = "
        SELECT
            ri.recipe_id,
            i.ingredient_id,
            i.name,
            i.image_url    AS image_path,
            ri.quantity,
            ri.unit
        FROM cart AS c
        JOIN recipe_ingredient AS ri ON c.recipe_id = ri.recipe_id
        JOIN ingredients        AS i  ON ri.ingredient_id = i.ingredient_id
        WHERE c.user_id = :user_id
    ";
    $stmt2 = $pdo->prepare($sqlIng);
    $stmt2->execute(['user_id' => $userId]);
    $ingredientsRaw = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // 4) สร้าง map วัตถุดิบจาก recipe_id
    $ingredientMap = [];
    $baseIngUrl = getBaseUrl() . '/uploads/ingredients';

    foreach ($ingredientsRaw as $row) {
        $rid = $row['recipe_id'];
        // ใช้ key 'image_path' เพราะเรา alias ข้างบน
        $img = sanitize($row['image_path'] ?: 'default_ingredient.png');

        $ingredientMap[$rid][] = [
            'ingredient_id' => (int)$row['ingredient_id'],
            'name'          => $row['name'],
            'quantity'      => (float)$row['quantity'],
            'unit'          => $row['unit'],
            'image_url'     => "{$baseIngUrl}/{$img}",
            'unit_conflict' => false
        ];
    }

    // 5) รวมข้อมูลกลับเป็น cart item พร้อม ingredients
    $data = [];
    $baseRecipeUrl = getBaseUrl() . '/uploads/recipes';

    foreach ($recipes as $r) {
        $rid            = $r['recipe_id'];
        $img            = sanitize($r['image_path'] ?: 'default_recipe.png');
        $cartServings   = (float)$r['cart_servings'];
        $recipeServings = (float)$r['recipe_servings'];

        // ปรับสัดส่วนวัตถุดิบตามจำนวนเสิร์ฟ
        $ingredients = [];
        if (isset($ingredientMap[$rid])) {
            foreach ($ingredientMap[$rid] as $ing) {
                $scaledQty = ($recipeServings > 0)
                    ? $ing['quantity'] * $cartServings / $recipeServings
                    : $ing['quantity'];

                $ingredients[] = [
                    'ingredient_id' => $ing['ingredient_id'],
                    'name'          => $ing['name'],
                    'quantity'      => round($scaledQty, 2),
                    'unit'          => $ing['unit'],
                    'image_url'     => $ing['image_url'],
                    'unit_conflict' => false
                ];
            }
        }

        $data[] = [
            'recipe_id'     => (int)$rid,
            'name'          => $r['name'],
            'prep_time'     => isset($r['prep_time']) ? (int)$r['prep_time'] : null,
            'average_rating'=> (float)$r['average_rating'],
            'review_count'  => (int)$r['review_count'],
            'nServings'     => $cartServings,
            'image_url'     => "{$baseRecipeUrl}/{$img}",
            'ingredients'   => $ingredients
        ];
    }

    // 6) ตอบกลับ JSON
    jsonOutput([
        'success'    => true,
        'totalItems' => count($data),
        'data'       => $data
    ]);
} catch (Exception $e) {
    jsonOutput([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}
