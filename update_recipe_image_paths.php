<?php
// update_recipe_image_paths.php
// สคริปต์อัปเดตรูปภาพหลักของแต่ละสูตรในตาราง recipe

// คืนผลลัพธ์เป็น JSON ง่าย ๆ (ไม่จำเป็นต้องใช้ แต่ช่วย debug)
header('Content-Type: application/json; charset=UTF-8');

// โหลด config + ฟังก์ชันช่วยเหลือ
require_once __DIR__ . '/inc/config.php';    // สร้างตัวแปร $pdo
require_once __DIR__ . '/inc/functions.php'; // sanitize()

try {
    $dir = __DIR__ . '/uploads/recipes';
    if (!is_dir($dir)) {
        throw new Exception("ไม่พบโฟลเดอร์: $dir");
    }

    $updated = 0;
    $errors  = [];

    // เปิดโฟลเดอร์และวนทุกไฟล์
    $dh = opendir($dir);
    while (($file = readdir($dh)) !== false) {
        // มองเฉพาะไฟล์ .jpg/.jpeg/.png ตาม pattern recipe_{id}.ext
        if (preg_match('/^recipe_(\d+)\.(jpe?g|png)$/i', $file, $m)) {
            $recipeId = (int)$m[1];
            $filename = sanitize($file);

            // อัปเดตลงฐานข้อมูล
            $stmt = $pdo->prepare(
                'UPDATE recipe
                    SET image_path = ?
                  WHERE recipe_id = ?'
            );
            $ok = $stmt->execute([$filename, $recipeId]);

            if ($ok && $stmt->rowCount() > 0) {
                $updated++;
            } else {
                // ถ้าอัปเดตไม่สำเร็จ (ไม่มี recipe_id นั้น หรือ error)
                $errors[] = "Failed to update recipe_id=$recipeId with file=$filename";
            }
        }
    }
    closedir($dh);

    // ส่งผลลัพธ์สรุปกลับ
    echo json_encode([
        'success'       => true,
        'updated_count' => $updated,
        'errors'        => $errors,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
