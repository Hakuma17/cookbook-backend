<?php
// get_allergy_list.php — รายการวัตถุดิบที่ผู้ใช้แพ้

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $userId = requireLogin();

    $rows = dbAll("
        SELECT i.ingredient_id, i.name, i.image_url
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
    ", [$userId]);

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[allergy_list] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
