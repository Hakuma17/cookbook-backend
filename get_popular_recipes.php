<?php
// get_popular_recipes.php
// คืนสูตรอาหารยอดนิยมเป็น JSON โดยใช้ชื่อ field ตามฐานข้อมูล

header('Content-Type: application/json; charset=UTF-8');

// เปิด error reporting (สำหรับตอนพัฒนา)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// โหลด config ฐานข้อมูลและฟังก์ชันเสริม
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ดึงสูตรยอดนิยม (เรียงตามคะแนนเฉลี่ย)
    $sql = '
        SELECT
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            (SELECT COUNT(*) FROM review rv WHERE rv.recipe_id = r.recipe_id) AS review_count
        FROM recipe r
        ORDER BY r.average_rating DESC
        LIMIT 10
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $recipes = [];

    // คำนวณ base URL สำหรับรูปภาพ
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');

    // 2) วนลูปข้อมูลเพื่อเติม image_url
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $imageFile = !empty($row['image_path']) ? sanitize($row['image_path']) : 'default_recipe.jpg';
        $row['image_url'] = $baseUrl . '/uploads/recipes/' . $imageFile;

        // ใส่ row ลงผลลัพธ์โดยไม่เปลี่ยนชื่อ field
        $recipes[] = [
            'recipe_id'      => (int) $row['recipe_id'],
            'name'           => $row['name'],
            'image_path'     => $row['image_path'],
            'prep_time'      => isset($row['prep_time']) ? (int)$row['prep_time'] : null,
            'average_rating' => (float)$row['average_rating'],
            'review_count'   => (int)$row['review_count'],
            'image_url'      => $row['image_url'],
        ];
    }

    // 3) ส่งออกเป็น JSON
    echo json_encode($recipes);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
    exit;
}
