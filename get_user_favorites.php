<?php
// get_user_favorites.php
// คืนรายการสูตรอาหารที่ผู้ใช้เพิ่มเป็น "สูตรโปรด"

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // ตรวจสอบว่า user_id ถูกส่งมาและเป็นตัวเลข
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid user_id']);
        exit;
    }

    $userId = (int)$_GET['user_id'];

    // ดึงสูตรที่ผู้ใช้กด favorite ไว้
    $sql = '
        SELECT
            f.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            (
                SELECT COUNT(*)
                FROM review rv
                WHERE rv.recipe_id = r.recipe_id
            ) AS review_count
        FROM favorites f
        JOIN recipe r ON f.recipe_id = r.recipe_id
        WHERE f.user_id = :user_id
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    // คำนวณ image URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');

    $favorites = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $imageFile = !empty($row['image_path']) ? sanitize($row['image_path']) : 'default_recipe.jpg';

        $favorites[] = [
            'recipe_id'      => (int)$row['recipe_id'],
            'name'           => $row['name'],
            'prep_time'      => isset($row['prep_time']) ? (int)$row['prep_time'] : null,
            'average_rating' => (float)$row['average_rating'],
            'review_count'   => (int)$row['review_count'],
            'image_url'      => $baseUrl . '/uploads/recipes/' . $imageFile,
        ];
    }

    echo json_encode($favorites);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
    exit;
}
