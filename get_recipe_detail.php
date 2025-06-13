<?php
// get_recipe_detail.php
// คืนรายละเอียดของสูตรอาหารตาม recipe_id (JSON)

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

try {
    // 1) รับ recipe_id
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ต้องระบุ recipe_id']);
        exit;
    }

    // 2) ดึงข้อมูลสูตรหลัก
    $sql = "
        SELECT
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.nServings,
            r.average_rating,
            (SELECT COUNT(*) FROM review WHERE recipe_id = r.recipe_id) AS review_count,
            r.created_at,
            r.source_reference
        FROM recipe r
        WHERE r.recipe_id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบสูตรอาหาร']);
        exit;
    }

    // 3) คำนวณ URL รูปภาพ (fallback default_recipe.png)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = rtrim("$scheme://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['SCRIPT_NAME']), '/');
    $file = !empty($row['image_path']) ? $row['image_path'] : 'default_recipe.png';
    $imageFile = function_exists('sanitize') ? sanitize($file) : $file;
    $singleUrl = "$baseUrl/uploads/recipes/$imageFile";

    // 4) คืน image_urls เป็นอาเรย์เสมอ
    $row['image_urls'] = [ $singleUrl ];
    unset($row['image_url']); // ถ้ามีฟิลด์เดิม

    // 5) ดึงรายการวัตถุดิบ
    $sqlIng = "
        SELECT
            ri.ingredient_id,
            i.name,
            i.image_url,
            ri.quantity,
            ri.unit,
            ri.grams_actual,
            ri.descrip
        FROM recipe_ingredient ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        WHERE ri.recipe_id = ?
        ORDER BY ri.id
    ";
    $stmtIng = $pdo->prepare($sqlIng);
    $stmtIng->execute([$id]);
    $row['ingredients'] = $stmtIng->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 6) ดึงขั้นตอนทำอาหาร
    $sqlStep = "
        SELECT step_number, description
        FROM step
        WHERE recipe_id = ?
        ORDER BY step_number
    ";
    $stmtStep = $pdo->prepare($sqlStep);
    $stmtStep->execute([$id]);
    $row['steps'] = $stmtStep->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 7) สรุปโภชนาการจากวัตถุดิบจริง
    $sqlNutri = "
        SELECT
            SUM(n.energy_kcal * ri.grams_actual / 100) AS calories,
            SUM(n.protein_g * ri.grams_actual / 100) AS protein,
            SUM(n.fat_g * ri.grams_actual / 100) AS fat,
            SUM(n.carbohydrate_g * ri.grams_actual / 100) AS carbs
        FROM recipe_ingredient ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        JOIN nutrition n ON i.nutrition_id = n.nutrition_id
        WHERE ri.recipe_id = ?
    ";
    $stmtNutri = $pdo->prepare($sqlNutri);
    $stmtNutri->execute([$id]);
    $nutri = $stmtNutri->fetch(PDO::FETCH_ASSOC);

    // fallback = 0 ถ้าค่าเป็น null
    $row['nutrition'] = [
        'calories' => round($nutri['calories'] ?? 0, 1),
        'fat'      => round($nutri['fat'] ?? 0, 1),
        'protein'  => round($nutri['protein'] ?? 0, 1),
        'carbs'    => round($nutri['carbs'] ?? 0, 1),
    ];

    // 8) favorite, user_rating, current_servings (ถ้า login)
    $userId = function_exists('getLoggedInUserId') ? getLoggedInUserId() : null;
    if ($userId) {
        // favorite
        $favStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM favorites WHERE recipe_id = ? AND user_id = ?"
        );
        $favStmt->execute([$id, $userId]);
        $row['is_favorited'] = $favStmt->fetchColumn() > 0;

        // user_rating
        $urStmt = $pdo->prepare(
            "SELECT rating FROM review WHERE recipe_id = ? AND user_id = ? LIMIT 1"
        );
        $urStmt->execute([$id, $userId]);
        $row['user_rating'] = $urStmt->fetchColumn() ?: null;

        // current_servings (ถ้าไม่มีใน cart ให้ default=1)
        $cartStmt = $pdo->prepare("
            SELECT nServings
            FROM cart
            WHERE recipe_id = ? AND user_id = ?
            LIMIT 1
        ");
        $cartStmt->execute([$id, $userId]);
        $row['current_servings'] = (int) ($cartStmt->fetchColumn() ?: $row['nServings']);

    } else {
        $row['is_favorited']     = false;
        $row['user_rating']      = null;
        $row['current_servings'] = 1;
    }

    // 9) ดึงความคิดเห็น
    $sqlCom = "
        SELECT
            r.user_id,
            u.profile_name AS user_name,
            u.path_imgProfile AS avatar_url,
            r.rating,
            r.comment,
            r.created_at
        FROM review r
        JOIN user u ON u.user_id = r.user_id
        WHERE r.recipe_id = ?
        ORDER BY r.created_at DESC
    ";
    $stmtCom = $pdo->prepare($sqlCom);
    $stmtCom->execute([$id]);
    $row['comments'] = $stmtCom->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 10) ดึงหมวดหมู่
    $sqlCat = "
        SELECT c.category_name
        FROM category_recipe cr
        JOIN category c ON cr.category_id = c.category_id
        WHERE cr.recipe_id = ?
    ";
    $stmtCat = $pdo->prepare($sqlCat);
    $stmtCat->execute([$id]);
    $row['categories'] = array_column(
        $stmtCat->fetchAll(PDO::FETCH_ASSOC),
        'category_name'
    );

    // 11) คืน JSON ทั้งหมด
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'เกิดข้อผิดพลาด',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
