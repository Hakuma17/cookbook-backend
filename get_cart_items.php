<?php
// get_cart_items.php
// คืนรายการเมนูในตะกร้าของผู้ใช้ พร้อมรายละเอียดสูตรอาหาร

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // ตรวจสอบ user_id
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid user_id']);
        exit;
    }

    $userId = (int)$_GET['user_id'];

    // SQL: JOIN ตาราง cart กับ recipe และนับ review
    $sql = '
        SELECT
            c.recipe_id,
            c.nServings,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            (
                SELECT COUNT(*)
                FROM review rv
                WHERE rv.recipe_id = r.recipe_id
            ) AS review_count
        FROM cart c
        JOIN recipe r ON c.recipe_id = r.recipe_id
        WHERE c.user_id = :user_id
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    // สร้าง base URL สำหรับโหลดภาพ
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $imageFile = !empty($row['image_path']) ? sanitize($row['image_path']) : 'default_recipe.jpg';

        $items[] = [
            'recipe_id'      => (int)$row['recipe_id'],
            'name'           => $row['name'],
            'prep_time'      => isset($row['prep_time']) ? (int)$row['prep_time'] : null,
            'average_rating' => (float)$row['average_rating'],
            'review_count'   => (int)$row['review_count'],
            'nServings'      => (float)$row['nServings'],
            'image_url'      => $baseUrl . '/uploads/recipes/' . $imageFile,
        ];
    }

    echo json_encode($items);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
    exit;
}
