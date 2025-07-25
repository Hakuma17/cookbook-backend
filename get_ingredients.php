<?php
// get_ingredients.php — รายชื่อวัตถุดิบทั้งหมด

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // 🔹 helper PDO wrapper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $rows = dbAll("
        SELECT
            ingredient_id     AS id,              -- ⭐️ alias ให้ชัด
            name,
            COALESCE(image_url, '') AS image_url, -- ⭐️ กัน NULL
            category
        FROM ingredients
        ORDER BY name ASC
    ");

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
