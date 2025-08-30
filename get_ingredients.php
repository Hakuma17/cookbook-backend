<?php
// get_ingredients.php — รายชื่อวัตถุดิบทั้งหมด

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php'; // ★ เรียกใช้ฟังก์ชันกลางจากที่นี่
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeImageUrl ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $isGroupedMode = isset($_GET['grouped']) && in_array($_GET['grouped'], ['1', 'true']);

    if ($isGroupedMode) {
        /*
         * โหมดกลุ่ม: คืนข้อมูลกลุ่มวัตถุดิบ
         * { success, groups: [{ group_name, representative_ingredient_id, ... }] }
         */
        $rows = dbAll("
            SELECT 
                g.group_name,
                rep.ingredient_id              AS representative_ingredient_id,
                rep.name                       AS representative_name,
                COALESCE(rep.image_url, '')    AS image_url,
                g.ingredient_count             AS item_count,
                COALESCE(rc.recipe_count, 0)   AS recipe_count,
                g.group_name                   AS catagorynew
            FROM (
                SELECT TRIM(newcatagory) AS group_name,
                       COUNT(*)          AS ingredient_count,
                       COALESCE(
                           MIN(CASE WHEN image_url IS NOT NULL AND TRIM(image_url) <> '' THEN ingredient_id END),
                           MIN(ingredient_id)
                       ) AS rep_id
                FROM ingredients
                WHERE newcatagory IS NOT NULL AND TRIM(newcatagory) <> ''
                GROUP BY TRIM(newcatagory)
            ) g
            JOIN ingredients rep ON rep.ingredient_id = g.rep_id
            LEFT JOIN (
                SELECT TRIM(i.newcatagory)            AS group_name,
                       COUNT(DISTINCT ri.recipe_id) AS recipe_count
                FROM ingredients i
                JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
                WHERE i.newcatagory IS NOT NULL AND TRIM(i.newcatagory) <> ''
                GROUP BY TRIM(i.newcatagory)
            ) rc ON rc.group_name = g.group_name
            ORDER BY g.group_name
        ");

        // ทำ absolute URL + default_group.png สำหรับโหมดกลุ่ม
        foreach ($rows as &$r) {
            // เรียกใช้ฟังก์ชันกลาง
            $r['image_url'] = normalizeImageUrl($r['image_url'], 'default_group.png');
        }
        unset($r);

        jsonOutput(['success' => true, 'groups' => $rows]);
        exit;
    }

    // ───────────────────────────────────────────────────────────────
    // โหมดรายตัว
    $rows = dbAll("
        SELECT
            ingredient_id           AS id,
            name,
            COALESCE(image_url, '') AS image_url,
            category
        FROM ingredients
        ORDER BY name ASC
    ");

    foreach ($rows as &$r) {
        // เรียกใช้ฟังก์ชันกลาง
        $r['image_url'] = normalizeImageUrl($r['image_url'], 'default_ingredients.png');
    }
    unset($r);

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}