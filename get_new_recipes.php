<?php
// get_new_recipes.php
// คืนสูตรอาหารใหม่ล่าสุดเป็น JSON โดยใช้ชื่อฟิลด์ตรงกับฐานข้อมูล

header('Content-Type: application/json; charset=UTF-8');

// เปิด error reporting (สำหรับ dev)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// โหลด config ฐานข้อมูล และฟังก์ชันเสริม
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ดึงสูตรเรียงตามวันที่สร้างใหม่สุด
    $sql = '
        SELECT
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            (SELECT COUNT(*) FROM review rv WHERE rv.recipe_id = r.recipe_id) AS review_count
        FROM recipe r
        ORDER BY r.created_at DESC
        LIMIT 10
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $recipes = [];

    // คำนวณ base URL สำหรับโหลดภาพ
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');

    // 2) วนลูปข้อมูลแต่ละแถว
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // fallback ถ้าไม่มีภาพ
        $imageFile = !empty($row['image_path']) ? sanitize($row['image_path']) : 'default_recipe.jpg';

        $recipes[] = [
            'recipe_id'      => (int)$row['recipe_id'],
            'name'           => $row['name'],
            'image_path'     => $row['image_path'],
            'prep_time'      => isset($row['prep_time']) ? (int)$row['prep_time'] : null,
            'average_rating' => (float)$row['average_rating'],
            'review_count'   => (int)$row['review_count'],
            'image_url'      => $baseUrl . '/uploads/recipes/' . $imageFile,
        ];
    }

    // 3) ส่ง JSON กลับ
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
