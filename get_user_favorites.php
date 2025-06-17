<?php
// get_user_favorites.php
// คืนรายการสูตรโปรดของผู้ใช้ (Favorites)

require_once __DIR__ . '/inc/config.php';       // เชื่อมต่อ DB (ตัวแปร $pdo)
require_once __DIR__ . '/inc/functions.php';    // ฟังก์ชันช่วยเหลือต่าง ๆ

try {
    // 1) ตรวจสอบว่าล็อกอินแล้วหรือยัง, ถ้าไม่จะส่ง JSON 401 แล้ว exit
    $userId = requireLogin();

    // 2) เตรียม SQL ดึงข้อมูลสูตรโปรด พร้อมนับจำนวนรีวิว
    $sql = "
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
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    // 3) สร้าง base URL สำหรับภาพ (รองรับ HTTP/HTTPS)
    $baseUrl = getBaseUrl() . '/uploads/recipes';

    // 4) แมปผลลัพธ์เป็น array พร้อมกำหนดค่า default ถ้าขาด
    $favorites = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // ถ้าไม่มี image_path ให้ใช้ default_recipe.png
        $img = sanitize($row['image_path'] ?: 'default_recipe.png');

        $favorites[] = [
            'recipe_id'      => (int)$row['recipe_id'],
            'name'           => $row['name'],
            'prep_time'      => isset($row['prep_time']) ? (int)$row['prep_time'] : null,
            'average_rating' => (float)$row['average_rating'],
            'review_count'   => (int)$row['review_count'],
            'image_url'      => "{$baseUrl}/{$img}",
        ];
    }

    // 5) ส่ง JSON กลับ (success + data)
    jsonOutput([
        'success' => true,
        'data'    => $favorites
    ]);

} catch (Exception $e) {
    // กรณีมีข้อผิดพลาด ส่ง 500 + ข้อความ error
    jsonOutput([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], 500);
}
