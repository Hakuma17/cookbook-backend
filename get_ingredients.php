<?php
// get_ingredients.php
// คืนข้อมูลวัตถุดิบทั้งหมด โดยใช้ชื่อ field ตามฐานข้อมูล

header('Content-Type: application/json; charset=UTF-8');

// เชื่อมต่อฐานข้อมูลและโหลดฟังก์ชัน
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ดึงข้อมูลวัตถุดิบทั้งหมด
    $sql = '
        SELECT ingredient_id, name, image_url, category
        FROM ingredients
        ORDER BY name ASC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $out = [];

    // 2) วนลูปแต่ละแถวจากผลลัพธ์
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // ถ้า image_url ไม่มีหรือว่าง → ให้ fallback เป็น ""
        $row['image_url'] = !empty($row['image_url']) ? $row['image_url'] : '';

        // จัดข้อมูลให้อยู่ในรูปแบบที่ปลอดภัยและตรงตามฐานข้อมูล
        $out[] = [
            'ingredient_id' => (int)$row['ingredient_id'],
            'name'          => $row['name'],
            'image_url'     => $row['image_url'],
            'category'      => $row['category']
        ];
    }

    // 3) ส่ง JSON กลับไปยัง client
    echo json_encode($out);
    exit;

} catch (Exception $e) {
    // กรณี error → ส่ง HTTP 500 พร้อม log
    http_response_code(500);
    error_log('get_ingredients error: ' . $e->getMessage());
    echo json_encode([
        'error' => true,
        'message' => 'เกิดข้อผิดพลาดในการดึงวัตถุดิบ'
    ]);
    exit;
}
